import csv
import io
import unittest

from .domain import Article, RunInput, valid_url
from .exports import render_journal
from .pipeline import run_pipeline


class ExportSecurityTests(unittest.IsolatedAsyncioTestCase):
    def test_sensitive_links_are_removed_before_serialization(self):
        secret = 'synthetic-secret-for-test'
        urls = [
            f'https://example.org/?API%255FKey={secret}',
            f'https://example.org/?x-amz-signature={secret}',
            f'https://example.org/?access_token[0]={secret}',
            f'https://example.org/#/callback?access_token={secret}',
            f'https://example.org/?code={secret}&state=example',
            f'https://user:{secret}@example.org/',
        ]
        for url in urls:
            with self.subTest(url=url):
                article = Article(id='article', url=url)
                self.assertFalse(valid_url(url))
                self.assertIsNone(article.url)
                self.assertTrue(article.url_credentials_removed)
                self.assertNotIn(secret, article.model_dump_json())

        public = 'https://example.org/article?id=42&utm_source=newsletter#details'
        self.assertTrue(valid_url(public))
        self.assertEqual(public, Article(id='public', url=public).url)

    async def test_blocked_url_is_excluded_with_safe_reason(self):
        class NoModelCalls:
            calls = []
            workflow_execution_id = None

            async def extract(self, *_args):
                raise AssertionError('A blocked URL must never reach extraction')

        payload = RunInput.model_validate({
            'control_time': '2026-11-07T12:00:00+03:00',
            'articles': [{
                'id': 'article', 'source': 'Example', 'text': 'Public article.',
                'url': 'https://example.org/?token=synthetic-secret-for-test',
                'published_at': '2026-11-07T10:00:00+03:00',
            }],
        })
        result = await run_pipeline(payload, NoModelCalls(), lambda _stage: None)
        row, = result['journal']
        self.assertEqual('исключён', row['status'])
        self.assertIn('данные доступа', row['reason'])
        self.assertNotIn('synthetic-secret-for-test', str(result))

    def test_csv_neutralizes_formulas_and_keeps_multiline_text_in_one_cell(self):
        row = {
            'id': '=2+2', 'status': 'исключён', 'group': '',
            'reason': '\ufeff ＠example', 'error': 'read failure\r=2+2',
        }
        exported, = csv.DictReader(io.StringIO(render_journal([row])))
        self.assertEqual('=2+2', row['id'])
        self.assertEqual("'=2+2", exported['id'])
        self.assertEqual("'\ufeff ＠example", exported['reason'])
        self.assertEqual('read failure\n=2+2', exported['error'])
