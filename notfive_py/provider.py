import asyncio
from dataclasses import dataclass, field
import json
import os
from time import monotonic
from typing import TypeVar

import httpx
from pydantic import BaseModel, ValidationError

from . import prompts
from .domain import Article, Audit, Extraction, Grouping
from .fingerprints import json_sha256
from .retrieval import retrieve_glossary


AI_URL = 'https://ai.api.cloud.yandex.net/v1/chat/completions'
WORKFLOWS_URL = 'https://serverless-workflows.api.cloud.yandex.net/workflows/v1/execution'
Reply = TypeVar('Reply', bound=BaseModel)


class ProviderError(Exception):
    pass


@dataclass(frozen=True)
class YandexSettings:
    folder: str
    model: str
    iam_token: str = field(repr=False)
    api_key: str = field(repr=False)
    workflow_id: str
    seed: int | None = None

    @classmethod
    def from_env(cls) -> 'YandexSettings':
        raw_seed = os.getenv('NOTFIVE_YANDEX_SEED', '').strip()
        try:
            seed = int(raw_seed) if raw_seed else None
        except ValueError:
            raise ValueError('NOTFIVE_YANDEX_SEED должен быть целым числом.') from None
        if seed is not None and not -(2**63) <= seed < 2**63:
            raise ValueError('NOTFIVE_YANDEX_SEED должен помещаться в знаковое 64-битное число.')
        return cls(
            os.getenv('NOTFIVE_YANDEX_FOLDER_ID', '').strip(),
            os.getenv('NOTFIVE_YANDEX_MODEL', 'yandexgpt-5-lite').strip(),
            os.getenv('NOTFIVE_YANDEX_IAM_TOKEN', '').strip(),
            os.getenv('NOTFIVE_YANDEX_API_KEY', '').strip(),
            os.getenv('NOTFIVE_YANDEX_WORKFLOW_ID', '').strip(),
            seed,
        )

    @property
    def configured(self) -> bool:
        return bool(self.folder and self.model and (self.iam_token or self.api_key) and (not self.workflow_id or self.iam_token))


def response_schema(reply: type[BaseModel]) -> dict:
    raw = reply.model_json_schema()
    definitions = raw.get('$defs', {})

    def inline(node: dict) -> dict:
        if '$ref' in node:
            return inline(definitions[node['$ref'].rsplit('/', 1)[1]])
        if 'anyOf' in node:
            return inline(next(option for option in node['anyOf'] if option.get('type') != 'null'))
        result = {'type': node['type']} if 'type' in node else {}
        if 'properties' in node:
            result['properties'] = {key: inline(value) for key, value in node['properties'].items()}
            result['required'] = list(result['properties'])
        if 'items' in node:
            result['items'] = inline(node['items'])
        return result

    return inline(raw)


class YandexProvider:
    def __init__(self, settings: YandexSettings, client: httpx.AsyncClient):
        self.settings = settings
        self.client = client
        self.calls: list[dict] = []
        self.workflow_execution_id: str | None = None

    async def request(self, method: str, url: str, headers: dict, body: dict | None = None) -> dict:
        if not self.settings.configured:
            raise ProviderError('Укажи каталог и учётные данные Яндекса в окружении Python-сервиса.')
        try:
            response = await self.client.request(method, url, headers=headers, json=body, follow_redirects=False)
        except httpx.RequestError as exc:
            raise ProviderError('Связь с Yandex Cloud прервалась.') from exc
        if not response.is_success:
            explanation = ' Проверь токен, каталог и роли сервисного аккаунта.' if response.status_code in (401, 403) else ''
            raise ProviderError(f'Yandex Cloud вернул HTTP {response.status_code}.{explanation}')
        try:
            data = response.json()
        except ValueError as exc:
            raise ProviderError('Yandex Cloud вернул ответ вне формата JSON.') from exc
        if not isinstance(data, dict):
            raise ProviderError('Yandex Cloud вернул неожиданный формат ответа.')
        return data

    async def complete(self, stage: str, instruction: str, payload: dict, reply: type[Reply], max_tokens: int) -> Reply:
        settings = self.settings
        model = f'gpt://{settings.folder}/{settings.model}'
        headers = {
            'Authorization': f'Bearer {settings.iam_token}' if settings.iam_token else f'Api-Key {settings.api_key}',
            'OpenAI-Project': settings.folder,
            'x-data-logging-enabled': 'false',
        }
        body = {
            'model': model,
            'messages': [
                {'role': 'system', 'content': instruction},
                {'role': 'user', 'content': json.dumps(payload, ensure_ascii=False)},
            ],
            'temperature': 0.1,
            'max_tokens': max_tokens,
            'response_format': {'type': 'json_schema', 'json_schema': {'name': stage, 'schema': response_schema(reply)}},
        }
        if settings.seed is not None:
            body['seed'] = settings.seed
        for attempt in (1, 2):
            started = monotonic()
            call = {
                'stage': stage, 'requested_model': model, 'usage': None, 'attempt': attempt,
                'temperature': body['temperature'], 'max_tokens': max_tokens, 'seed': settings.seed,
                'request_sha256': json_sha256(body),
            }
            self.calls.append(call)
            try:
                data = await self.request('POST', AI_URL, headers, body)
                call.update(model=data.get('model'), usage=data.get('usage'),
                            system_fingerprint=data.get('system_fingerprint'))
                choice = data['choices'][0]
                if not isinstance(choice, dict):
                    raise TypeError('Unexpected choice type')
                if choice.get('finish_reason') in ('length', 'content_filter'):
                    raise ProviderError(f'Модель не завершила ответ на этапе {stage}.')
                parsed = reply.model_validate_json(choice['message']['content'], strict=True)
                call['response_sha256'] = json_sha256(parsed.model_dump())
                return parsed
            except ValidationError as exc:
                first = exc.errors(include_input=False)[0]
                if first['type'] == 'json_invalid' and attempt == 1:
                    body['messages'][0]['content'] += '\nВерни только валидный JSON по схеме, без Markdown и комментариев.'
                    continue
                field = '.'.join(str(part) for part in first['loc']) or 'JSON'
                raise ProviderError(f'Ответ модели на этапе {stage} не прошёл проверку поля {field} ({first["type"]}).') from exc
            except (KeyError, IndexError, TypeError) as exc:
                raise ProviderError(f'Модель вернула неполный структурированный ответ на этапе {stage}.') from exc
            finally:
                call['elapsed_seconds'] = round(monotonic() - started, 3)

    async def extract(self, articles: list[Article], control_time: str) -> list[Extraction | ProviderError]:
        if self.settings.workflow_id:
            return await self.extract_workflow(articles, control_time)
        slots = asyncio.Semaphore(3)

        async def one(article: Article) -> Extraction | ProviderError:
            async with slots:
                try:
                    return await self.complete('extract', prompts.EXTRACT, {
                        'control_time': control_time,
                        'article': article.model_dump(exclude={'url'}, exclude_none=True),
                        'glossary': retrieve_glossary(article.title, article.text or ''),
                    }, Extraction, 1500)
                except ProviderError as exc:
                    return exc

        return await asyncio.gather(*(one(article) for article in articles))

    async def extract_workflow(self, articles: list[Article], control_time: str) -> list[Extraction | ProviderError]:
        headers = {'Authorization': f'Bearer {self.settings.iam_token}'}
        body = {
            'workflowId': self.settings.workflow_id,
            'input': {'inputJson': json.dumps({
                'control_time': control_time, 'articles': [article.model_dump(exclude={'url'}) for article in articles],
            }, ensure_ascii=False)},
        }
        started = await self.request('POST', f'{WORKFLOWS_URL}/start', headers, body)
        execution_id = started.get('executionId')
        if not isinstance(execution_id, str) or not execution_id.isalnum():
            raise ProviderError('Workflows не вернул ID запуска.')
        self.workflow_execution_id = execution_id
        deadline = monotonic() + 360
        while monotonic() < deadline:
            state = (await self.request('GET', f'{WORKFLOWS_URL}/{execution_id}', headers)).get('execution', {})
            if state.get('status') == 'FINISHED':
                try:
                    rows = json.loads(state['result']['resultJson'])['extractions']
                    if not isinstance(rows, list) or len(rows) != len(articles):
                        raise ValueError('Unexpected result count')
                except (ValueError, KeyError, TypeError) as exc:
                    raise ProviderError('Workflows должен возвращать массив extractions. Сохрани YaWL версии v3.') from exc
                request_hash = json_sha256(body)
                return [self.read_workflow_row(row, request_hash) for row in rows]
            if state.get('status') not in ('QUEUED', 'RUNNING'):
                raise ProviderError(f'Workflows завершился со статусом {state.get("status") or "неизвестен"}.')
            await asyncio.sleep(2)
        raise ProviderError('Истекло время ожидания результата Workflows.')

    def read_workflow_row(self, row: dict, request_hash: str) -> Extraction | ProviderError:
        if not isinstance(row, dict):
            return ProviderError('Workflows вернул запись неизвестного формата.')
        call = {'stage': 'workflow_extract', 'model': row.get('model_version'), 'usage': row.get('usage'),
                'request_sha256': request_hash}
        self.calls.append(call)
        try:
            parsed = Extraction.model_validate(row.get('extraction'), strict=True)
            call['response_sha256'] = json_sha256(parsed.model_dump())
            return parsed
        except ValidationError:
            return ProviderError('Workflows не вернул разбор публикации.')

    async def group(self, payload: dict) -> Grouping:
        return await self.complete('group', prompts.GROUP, payload, Grouping, 6500)

    async def audit(self, payload: dict) -> Audit:
        return await self.complete('audit', prompts.AUDIT, payload, Audit, 2200)
