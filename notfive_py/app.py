import asyncio
from contextlib import asynccontextmanager
from hmac import compare_digest
import logging
import os
from pathlib import Path
from time import monotonic
from typing import Annotated

from fastapi import APIRouter, BackgroundTasks, FastAPI, Header, HTTPException, Request, Security
from fastapi.exception_handlers import http_exception_handler
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse, Response
from fastapi.routing import APIRoute
from fastapi.security import HTTPBearer
import httpx
from starlette.exceptions import HTTPException as StarletteHTTPException
from starlette.middleware.trustedhost import TrustedHostMiddleware

from .domain import RunInput
from .exports import ARTIFACT_TYPES, ExportError
from .fingerprints import run_input_data
from .pipeline import run_pipeline
from .provider import ProviderError, YandexProvider, YandexSettings
from .store import QueueFull, RequestConflict, RunStore


ROOT = Path(__file__).resolve().parents[1]
LOGGER = logging.getLogger(__name__)
MAX_REQUEST_BYTES = 16 * 1024 * 1024
BEARER = HTTPBearer(scheme_name='NotFiveBearer', auto_error=False)


class ProtectedRunRoute(APIRoute):
    def get_route_handler(self):
        handle = super().get_route_handler()

        async def protected(request: Request):
            token = request.app.state.run_api_token
            if not token:
                raise HTTPException(503, 'Задай токен доступа к API notFive на сервере.')
            credentials = await BEARER(request)
            if credentials is None or not compare_digest(credentials.credentials.encode(), token.encode()):
                raise HTTPException(401, 'Нужен действительный токен notFive.', headers={'WWW-Authenticate': 'Bearer'})

            try:
                declared_size = int(request.headers.get('content-length', '0'))
            except ValueError as exc:
                raise HTTPException(400, 'Некорректный Content-Length.') from exc
            if declared_size < 0:
                raise HTTPException(400, 'Некорректный Content-Length.')
            if declared_size > MAX_REQUEST_BYTES:
                raise HTTPException(413, 'Тело запроса слишком большое.')

            received = 0

            async def limited_receive():
                nonlocal received
                message = await request.receive()
                received += len(message.get('body', b''))
                if received > MAX_REQUEST_BYTES:
                    raise HTTPException(413, 'Тело запроса слишком большое.')
                return message

            response = await handle(Request(request.scope, receive=limited_receive))
            response.headers['Cache-Control'] = 'no-store'
            return response

        return protected


def create_app(settings=None, store_path=None, provider_factory=YandexProvider, *, api_token=None) -> FastAPI:
    settings = settings or YandexSettings.from_env()
    store = RunStore(Path(store_path or os.getenv('NOTFIVE_RUN_DB') or ROOT / 'var/notfive-python/runs.sqlite3'))
    run_slot = asyncio.Semaphore(1)

    @asynccontextmanager
    async def lifespan(_app):
        store.prepare()
        yield

    app = FastAPI(title='notFive · Python API', lifespan=lifespan)
    app.state.run_api_token = os.getenv('NOTFIVE_API_TOKEN', '').strip() if api_token is None else api_token.strip()
    hosts = [host.strip().lower() for host in os.getenv('NOTFIVE_ALLOWED_HOSTS', 'localhost,127.0.0.1').split(',')
             if host.strip()]
    if not hosts or any('*' in host for host in hosts):
        raise ValueError('NOTFIVE_ALLOWED_HOSTS должен содержать список разрешённых хостов без подстановок.')
    app.add_middleware(TrustedHostMiddleware, allowed_hosts=hosts, www_redirect=False)

    # The route handler checks credentials before FastAPI reads the request body.
    runs = APIRouter(prefix='/api/v1/runs', route_class=ProtectedRunRoute, dependencies=[Security(BEARER)])

    @app.exception_handler(StarletteHTTPException)
    async def handle_http_exception(request: Request, error: StarletteHTTPException):
        response = await http_exception_handler(request, error)
        response.headers['Cache-Control'] = 'no-store'
        return response

    @app.exception_handler(RequestValidationError)
    async def handle_validation_exception(_request: Request, error: RequestValidationError):
        detail = [{'loc': entry['loc'], 'type': entry['type'], 'msg': 'Некорректное значение.'}
                  for entry in error.errors()]
        return JSONResponse({'detail': detail}, status_code=422, headers={'Cache-Control': 'no-store'})

    @app.get('/api/v1/example', response_model=RunInput)
    def example():
        return RunInput.model_validate_json((ROOT / 'docs/case/fixtures/five-articles.input.json').read_bytes())

    @app.get('/api/v1/config')
    def config():
        return {'configured': settings.configured, 'extraction': 'workflows' if settings.workflow_id else 'yandex'}

    async def process(run_id: str, payload: RunInput):
        async with run_slot:
            started = monotonic()
            store.update(run_id, status='running', stage='input')

            def stage(name: str):
                store.update(run_id, status='running', stage=name)

            try:
                async with httpx.AsyncClient(timeout=httpx.Timeout(70, connect=10)) as client:
                    provider = provider_factory(settings, client)
                    result = await asyncio.wait_for(run_pipeline(payload, provider, stage), timeout=600)
                result['elapsed_seconds'] = round(monotonic() - started, 2)
                store.update(run_id, status='completed', result=result)
            except (ProviderError, ExportError) as exc:
                store.update(run_id, status='failed', error=str(exc))
            except asyncio.TimeoutError:
                store.update(run_id, status='failed', error='Истекло время обработки набора.')
            except asyncio.CancelledError:
                store.update(run_id, status='failed', error='Обработка прервана.')
                raise
            except Exception:
                LOGGER.exception('Failed run %s', run_id)
                store.update(run_id, status='failed', error='Сервис не смог завершить обработку.')

    @runs.post('', status_code=202)
    async def start(payload: RunInput, tasks: BackgroundTasks,
                    idempotency_key: Annotated[str | None, Header(max_length=100)] = None):
        if not settings.configured and payload.articles:
            raise HTTPException(503, 'Укажи каталог и учётные данные Яндекса в окружении Python-сервиса.')
        try:
            run_id, is_new = store.create(run_input_data(payload), idempotency_key)
        except QueueFull as exc:
            raise HTTPException(429, 'Очередь заполнена. Дождись окончания текущих запусков.') from exc
        except RequestConflict as exc:
            raise HTTPException(409, 'Этот ключ запуска уже использован для другого набора.') from exc
        if is_new:
            tasks.add_task(process, run_id, payload)
        return {'id': run_id}

    @runs.get('/{run_id}')
    def get_run(run_id: str):
        run = store.get(run_id)
        if run is None:
            raise HTTPException(404, 'Запуск не найден.')
        return run

    @runs.get('/{run_id}/artifacts/{filename}')
    def get_artifact(run_id: str, filename: str):
        run = store.get(run_id)
        if run is None or not run['result'] or filename not in ARTIFACT_TYPES:
            raise HTTPException(404, 'Файл запуска не найден.')
        return Response(run['result']['artifacts'][filename], media_type=ARTIFACT_TYPES[filename], headers={
            'Content-Disposition': f'attachment; filename="{filename}"',
        })

    app.include_router(runs)
    return app


app = create_app()
