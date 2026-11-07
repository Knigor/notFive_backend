import csv
import io
import json
from pathlib import Path
import re
from tempfile import TemporaryDirectory
import unittest

from fastapi.testclient import TestClient
import httpx

from .app import create_app
from .domain import Audit, Extraction, Grouping, RunInput
from .exports import visible_length
from .fingerprints import json_sha256
from .pipeline import analyze_articles, quoted_span, run_pipeline
from .provider import ProviderError, YandexProvider, YandexSettings, response_schema


FIXTURES = Path(__file__).resolve().parent.parent / 'docs/case/fixtures'


class FixtureProvider:
    """Repeatable model replies for exercising validation and the API in isolation."""

    def __init__(self, *_args):
        self.calls = []
        self.workflow_execution_id = None
        self.extracted_ids = []

    async def extract(self, articles, _control_time):
        self.extracted_ids = [article.id for article in articles]
        replies = []
        for article in articles:
            fragment = article.text.split('\n\n')[0]
            relevant = 'CVE-' in fragment
            replies.append(Extraction.model_validate({
                'source_id': article.id, 'relevant': relevant, 'reason': '' if relevant else 'Вне темы ИБ.',
                'claims': [{'claim': fragment[:230], 'evidence': fragment}] if relevant else [],
            }))
        return replies

    async def group(self, payload):
        groups = {}
        for fact in payload['facts']:
            cve = re.search(r'CVE-\d+-\d+', fact['evidence']).group()
            groups.setdefault(cve, []).append(fact)
        events = []
        for cve, facts in groups.items():
            if cve == 'CVE-2025-59287':
                summary = 'В бюллетене Microsoft эксплуатация не отмечена. Исследователи Google сообщили о кампании.'
            elif any('каталог' in fact['evidence'] for fact in facts):
                summary = 'CISA внесла Copy Fail в каталог известных эксплуатируемых уязвимостей.'
            else:
                summary = f'Опубликованы сведения об уязвимости {cve}.'
            events.append({
                'title': cve, 'summary': summary, 'post': summary, 'consequences': 'Масштаб уточняется.',
                'fact_ids': [fact['id'] for fact in facts],
                'priority': {'impact': 3 if cve == 'CVE-2026-42167' else 2, 'scale': 0, 'urgency': 1, 'relevance': 2},
            })
        return Grouping.model_validate({'events': events})

    async def audit(self, payload):
        return Audit.model_validate({'verdicts': [
            {'event_id': event['event_id'], 'supported': True, 'reason': ''} for event in payload['events']
        ]})


class PipelineTests(unittest.IsolatedAsyncioTestCase):
    async def test_case_fixtures(self):
        suite = json.loads((FIXTURES / 'real-site-cases.json').read_bytes())['cases']
        for name, case in suite.items():
            with self.subTest(name=name):
                provider = FixtureProvider()
                result = await run_pipeline(RunInput.model_validate(case['input']), provider, lambda _stage: None)
                self.assertEqual(case['expected']['event_count'], result['event_count'])
                csv_rows = list(csv.DictReader(io.StringIO(result['artifacts']['journal.csv'])))
                self.assertEqual(case['expected']['journal_row_count'], len(csv_rows))
                self.assertEqual([item['id'] for item in case['input']['articles']], [item['id'] for item in csv_rows])
                self.assertLessEqual(visible_length(result['artifacts']['digest.md']), 4000)
                self.assertLessEqual(visible_length(result['artifacts']['post.md']), 2000)
                if name == 'duplicate':
                    self.assertEqual(2, len(result['events'][0]['sources']))
                    self.assertEqual({'включён', 'объединён'}, {row['status'] for row in csv_rows})
                    self.assertIn('Приоритет 5/12', result['events'][0]['reason'])
                if name == 'outside_window_and_update':
                    self.assertEqual(['copy-fail-update-securitylab'], provider.extracted_ids)
                    self.assertIn('каталог', result['artifacts']['digest.md'])
                if name == 'conflicting_reports':
                    self.assertIn('Microsoft', result['artifacts']['post.md'])
                    self.assertIn('Google', result['artifacts']['post.md'])
                if name == 'read_error':
                    self.assertTrue(csv_rows[0]['error'])
                if name == 'embedded_instruction':
                    self.assertNotIn('NOTFIVE_INJECTION_CANARY', result['artifacts']['digest.md'])
                    self.assertNotIn('redirect-notfive', result['artifacts']['email.html'])

    async def test_provider_failure_is_reported(self):
        class FailingProvider(FixtureProvider):
            async def extract(self, articles, _control_time):
                return [ProviderError('Тестовый отказ')] * len(articles)

        payload = RunInput.model_validate_json((FIXTURES / 'five-articles.input.json').read_bytes())
        with self.assertRaises(ProviderError):
            await run_pipeline(payload, FailingProvider(), lambda _stage: None)

    def test_exact_quote_recovery(self):
        self.assertEqual('На Linux.', quoted_span('На Linux.', 'на Linux.')[0])
        self.assertIsNone(quoted_span('Linux', 'Windows'))
        self.assertIsNone(quoted_span('На Linux. На Linux.', 'на Linux.'))

    def test_yandex_requires_every_property(self):
        schema = response_schema(Extraction)
        self.assertEqual(set(schema['properties']), set(schema['required']))
        self.assertIn('reason', schema['required'])

    async def test_yandex_retries_invalid_json_once(self):
        valid_reply = json.dumps({'source_id': 'article', 'relevant': False, 'reason': '', 'claims': []})
        replies = iter(['{', valid_reply])

        def respond(_request):
            return httpx.Response(200, json={
                'choices': [{'message': {'content': next(replies)}}],
                'usage': {'total_tokens': 100},
            })

        settings = YandexSettings('folder', 'yandexgpt-5-lite', 'test-token', '', '')
        async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
            provider = YandexProvider(settings, client)
            result = await provider.complete('extract', 'Test', {}, Extraction, 100)

        self.assertEqual('article', result.source_id)
        self.assertEqual([1, 2], [call['attempt'] for call in provider.calls])
        self.assertEqual(200, sum(call['usage']['total_tokens'] for call in provider.calls))
        self.assertNotEqual(provider.calls[0]['request_sha256'], provider.calls[1]['request_sha256'])

    async def test_yandex_records_safe_reproducibility_metadata(self):
        body = {}
        reply = {'source_id': 'article', 'relevant': False, 'reason': '', 'claims': []}

        def respond(request):
            self.assertEqual('Bearer synthetic-iam-token', request.headers['Authorization'])
            self.assertNotIn(b'synthetic-iam-token', request.content)
            body.update(json.loads(request.content))
            return httpx.Response(200, json={
                'model': 'fixture-model', 'system_fingerprint': 'fixture-backend',
                'choices': [{'message': {'content': json.dumps(reply)}}],
            })

        settings = YandexSettings('folder', 'model', 'synthetic-iam-token', 'synthetic-api-key', '', seed=42)
        async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
            provider = YandexProvider(settings, client)
            await provider.complete('extract', 'Test', {}, Extraction, 100)

        call, = provider.calls
        self.assertEqual(42, body['seed'])
        self.assertEqual(42, call['seed'])
        self.assertEqual('fixture-backend', call['system_fingerprint'])
        self.assertEqual(json_sha256(body), call['request_sha256'])
        self.assertEqual(json_sha256(reply), call['response_sha256'])
        self.assertNotIn('synthetic-', repr(settings))
        self.assertNotIn('synthetic-', repr(call))

    async def test_yandex_rejects_wrong_type_and_truncation(self):
        settings = YandexSettings('folder', 'model', 'test-token', '', '')
        for value, finish_reason, expected in [('false', 'stop', 'relevant'), (False, 'length', 'не завершила')]:
            with self.subTest(value=value, finish_reason=finish_reason):
                content = json.dumps({'source_id': 'article', 'relevant': value, 'reason': '', 'claims': []})
                transport = httpx.MockTransport(lambda _request: httpx.Response(200, json={
                    'choices': [{'finish_reason': finish_reason, 'message': {'content': content}}],
                }))
                async with httpx.AsyncClient(transport=transport) as client:
                    provider = YandexProvider(settings, client)
                    with self.assertRaisesRegex(ProviderError, expected):
                        await provider.complete('extract', 'Test', {}, Extraction, 100)
                self.assertEqual(1, len(provider.calls))

    async def test_direct_api_retrieves_context_and_restores_verified_source(self):
        case = json.loads((FIXTURES / 'real-site-cases.json').read_bytes())['cases']['duplicate']
        payload = RunInput.model_validate(case['input'])
        article_ids = [article.id for article in payload.articles]

        def respond(request):
            self.assertEqual('https://ai.api.cloud.yandex.net/v1/chat/completions', str(request.url))
            self.assertEqual('Bearer test-token', request.headers['Authorization'])
            body = json.loads(request.content)
            schema = body['response_format']['json_schema']
            stage = schema['name']
            data = json.loads(body['messages'][1]['content'])
            if stage == 'extract':
                article = data['article']
                self.assertNotIn('url', article)
                self.assertIn('Уязвимости и компрометация', [section['name'] for section in data['glossary']['sections']])
                output = {'source_id': article['id'], 'relevant': True, 'reason': '', 'claims': [
                    {'claim': 'Сообщается об уязвимости Copy Fail.', 'evidence': article['text'].split('. ')[0] + '.'},
                ]}
            elif stage == 'group':
                self.assertEqual(article_ids, [fact['source_id'] for fact in data['facts']])
                output = {'events': [{
                    'title': 'Уязвимость Copy Fail', 'summary': 'Сообщается об уязвимости Copy Fail. Она затрагивает ядро Linux.',
                    'post': 'Сообщается об уязвимости Copy Fail в ядре Linux.',
                    'consequences': 'Есть риск локального повышения привилегий.',
                    'fact_ids': ['f1'], 'priority': {'impact': 3, 'scale': 0, 'urgency': 0, 'relevance': 3},
                }]}
            else:
                self.assertEqual('audit', stage)
                event, = data['events']
                self.assertEqual('f1', event['evidence'][0]['id'])
                self.assertEqual('f2', event['related_evidence'][0]['id'])
                output = {'verdicts': [{
                    'event_id': event['event_id'], 'supported': True, 'reason': '', 'related_fact_ids': ['f2'],
                }]}
            return httpx.Response(200, json={'choices': [{'message': {'content': json.dumps(output)}}]})

        settings = YandexSettings('folder', 'yandexgpt-5-lite', 'test-token', '', '')
        async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
            result = await analyze_articles(payload, YandexProvider(settings, client), lambda _stage: None)

        self.assertNotIn('artifacts', result)
        event, = result['events']
        self.assertEqual(article_ids, event['source_ids'])
        self.assertEqual(['включён', 'объединён'], [row['status'] for row in result['journal']])

    async def test_audit_rejects_unknown_fact_and_duplicate_verdicts(self):
        class BadAuditProvider(FixtureProvider):
            def __init__(self, duplicate):
                super().__init__()
                self.duplicate = duplicate

            async def audit(self, payload):
                event, = payload['events']
                verdict = {'event_id': event['event_id'], 'supported': True, 'reason': '', 'related_fact_ids': ['f999']}
                return Audit.model_validate({'verdicts': [verdict] * (2 if self.duplicate else 1)})

        case = json.loads((FIXTURES / 'real-site-cases.json').read_bytes())['cases']['duplicate']
        payload = RunInput.model_validate(case['input'])
        for duplicate, expected in [(False, 'неизвестный ID'), (True, 'одно решение')]:
            with self.subTest(duplicate=duplicate):
                result = await analyze_articles(payload, BadAuditProvider(duplicate), lambda _stage: None)
                self.assertEqual([], result['events'])
                self.assertIn(expected, result['journal'][0]['error'])

    async def test_workflow_v3_adapter(self):
        payload = RunInput.model_validate_json((FIXTURES / 'five-articles.input.json').read_bytes())
        article = payload.articles[0]

        def respond(request):
            if request.method == 'POST':
                body = json.loads(request.content)
                self.assertEqual('workflow-v3', body['workflowId'])
                sent_article = json.loads(body['input']['inputJson'])['articles'][0]
                self.assertEqual(article.id, sent_article['id'])
                self.assertNotIn('url', sent_article)
                return httpx.Response(200, json={'executionId': 'abc123'})
            self.assertTrue(request.url.path.endswith('/abc123'))
            rows = [{'extraction': {'source_id': article.id, 'relevant': True, 'reason': '', 'claims': []},
                     'model_version': 'example', 'usage': {'totalTokens': '100'}}]
            return httpx.Response(200, json={'execution': {'status': 'FINISHED', 'result': {'resultJson': json.dumps({'extractions': rows})}}})

        settings = YandexSettings('folder', 'model', 'test-token', '', 'workflow-v3')
        async with httpx.AsyncClient(transport=httpx.MockTransport(respond)) as client:
            provider = YandexProvider(settings, client)
            reply, = await provider.extract([article], payload.control_time)
        self.assertEqual(article.id, reply.source_id)
        self.assertEqual('abc123', provider.workflow_execution_id)
        self.assertEqual('100', provider.calls[0]['usage']['totalTokens'])

    async def test_top_five_and_stable_order(self):
        sample = RunInput.model_validate_json((FIXTURES / 'five-articles.input.json').read_bytes())
        payload = RunInput(control_time=sample.control_time, articles=[
            sample.articles[0].model_copy(update={'id': f'a{i}', 'text': f'Новая уязвимость CVE-2026-{10000 + i}.'})
            for i in range(6)
        ])
        result = await run_pipeline(payload, FixtureProvider(), lambda _stage: None)
        self.assertEqual(5, result['event_count'])
        self.assertEqual(['a0', 'a1', 'a2', 'a3', 'a4'], [event['source_ids'][0] for event in result['events']])
        self.assertEqual('исключён', result['journal'][-1]['status'])
        self.assertIn('ниже пятого', result['journal'][-1]['reason'])


class ApiTests(unittest.TestCase):
    def test_api_run_and_downloads_without_site(self):
        settings = YandexSettings('test-folder', 'test-model', 'test-token', '', '')
        with TemporaryDirectory() as directory:
            app = create_app(settings, Path(directory) / 'test.db', FixtureProvider, api_token='local-test-token')
            with TestClient(app, base_url='http://localhost', headers={'Authorization': 'Bearer local-test-token'}) as client:
                self.assertEqual(404, client.get('/').status_code)
                self.assertEqual(404, client.get('/static/site.js').status_code)
                payload = client.get('/api/v1/example').json()
                first = client.post('/api/v1/runs', json=payload, headers={'Idempotency-Key': 'smoke'})
                self.assertEqual(202, first.status_code)
                run_id = first.json()['id']
                second = client.post('/api/v1/runs', json=payload, headers={'Idempotency-Key': 'smoke'})
                self.assertEqual(run_id, second.json()['id'])
                payload['articles'] = []
                self.assertEqual(409, client.post('/api/v1/runs', json=payload, headers={'Idempotency-Key': 'smoke'}).status_code)
                route = f'/api/v1/runs/{run_id}'
                self.assertEqual(401, client.get(route, headers={'Authorization': ''}).status_code)
                response = client.get(route)
                self.assertEqual('no-store', response.headers['cache-control'])
                run = response.json()
                self.assertEqual('completed', run['status'], run['error'])
                self.assertEqual(['proftpd-securitylab'], run['result']['events'][0]['source_ids'])
                self.assertEqual(2, run['result']['event_count'])
                self.assertEqual(5, len(run['result']['journal']))
                for filename in ('digest.md', 'post.md', 'email.html', 'journal.csv'):
                    response = client.get(f'/api/v1/runs/{run_id}/artifacts/{filename}')
                    self.assertEqual(200, response.status_code)
                    self.assertIn(filename, response.headers['content-disposition'])
