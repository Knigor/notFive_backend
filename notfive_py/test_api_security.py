import os
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

from fastapi.testclient import TestClient
import httpx

from .app import MAX_REQUEST_BYTES, create_app


AUTH_HEADERS = {'Authorization': 'Bearer local-test-token'}


class ApiSecurityTests(unittest.TestCase):
    def setUp(self):
        directory = TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.store = Path(directory.name) / 'test.db'
        hosts = patch.dict(os.environ, {'NOTFIVE_ALLOWED_HOSTS': 'localhost,127.0.0.1'})
        hosts.start()
        self.addCleanup(hosts.stop)

    def test_every_run_route_requires_bearer(self):
        app = create_app(store_path=self.store, api_token='local-test-token')
        with TestClient(app, base_url='http://localhost') as client:
            for method, endpoint in (
                ('POST', '/api/v1/runs'),
                ('GET', '/api/v1/runs/missing'),
                ('GET', '/api/v1/runs/missing/artifacts/journal.csv'),
            ):
                with self.subTest(endpoint=endpoint):
                    response = client.request(method, endpoint)
                    self.assertEqual(401, response.status_code)
                    self.assertEqual('Bearer', response.headers['WWW-Authenticate'])
                    self.assertEqual('no-store', response.headers['Cache-Control'])
            response = client.get('/api/v1/runs/missing', headers={'Authorization': 'Bearer wrong'})
            self.assertEqual(401, response.status_code)
            response = client.get('/api/v1/runs/missing', headers=AUTH_HEADERS)
            self.assertEqual(404, response.status_code)
            self.assertEqual('no-store', response.headers['Cache-Control'])

    def test_unset_token_fails_closed_and_docs_describe_bearer(self):
        app = create_app(store_path=self.store, api_token='')
        with TestClient(app, base_url='http://127.0.0.1:8090') as client:
            self.assertEqual(503, client.post('/api/v1/runs', json={}).status_code)
            self.assertEqual(200, client.get('/api/v1/example').status_code)
            self.assertEqual(200, client.get('/api/v1/config').status_code)
            schema = client.get('/openapi.json').json()
            self.assertEqual('bearer', schema['components']['securitySchemes']['NotFiveBearer']['scheme'])
            self.assertEqual([{'NotFiveBearer': []}], schema['paths']['/api/v1/runs']['post']['security'])

    def test_host_must_be_on_explicit_allowlist(self):
        with patch.dict(os.environ, {'NOTFIVE_ALLOWED_HOSTS': 'localhost,team.example'}):
            app = create_app(store_path=self.store, api_token='local-test-token')
        with TestClient(app, base_url='http://localhost') as client:
            self.assertEqual(400, client.get('/api/v1/config', headers={'Host': 'attacker.invalid'}).status_code)
            self.assertEqual(200, client.get('/api/v1/config', headers={'Host': 'team.example'}).status_code)

    def test_rejects_large_body_and_redacts_validation_input(self):
        app = create_app(store_path=self.store, api_token='local-test-token')
        with TestClient(app, base_url='http://localhost', headers=AUTH_HEADERS) as client:
            response = client.post('/api/v1/runs', content='{}', headers={
                'Content-Type': 'application/json', 'Content-Length': str(MAX_REQUEST_BYTES + 1),
            })
            self.assertEqual(413, response.status_code)
            response = client.post('/api/v1/runs', json={'control_time': 'synthetic-private-value', 'articles': []})
            self.assertEqual(422, response.status_code)
            self.assertNotIn('synthetic-private-value', response.text)
            self.assertEqual(['body', 'control_time'], response.json()['detail'][0]['loc'])
            self.assertEqual('no-store', response.headers['Cache-Control'])


class StreamingSecurityTests(unittest.IsolatedAsyncioTestCase):
    async def test_caps_streamed_body_without_content_length(self):
        async def body_chunks():
            yield b'{"ignored":"' + b'x' * 24
            yield b'x' * 36 + b'"}'

        with TemporaryDirectory() as directory:
            with patch.dict(os.environ, {'NOTFIVE_ALLOWED_HOSTS': 'localhost'}):
                app = create_app(store_path=Path(directory) / 'test.db', api_token='local-test-token')
            with patch('notfive_py.app.MAX_REQUEST_BYTES', 64):
                async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url='http://localhost') as client:
                    response = await client.post('/api/v1/runs', content=body_chunks(), headers={
                        **AUTH_HEADERS, 'Content-Type': 'application/json',
                    })
        self.assertEqual(413, response.status_code)
        self.assertEqual('no-store', response.headers['Cache-Control'])
