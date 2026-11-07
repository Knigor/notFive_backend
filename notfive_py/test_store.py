import json
import os
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

from .fingerprints import json_sha256
from .store import RequestConflict, RunStore


class StoreTests(unittest.TestCase):
    def setUp(self):
        directory = TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.store = RunStore(Path(directory.name) / 'runs.sqlite3')

    def test_hashes_new_input_and_keeps_legacy_idempotency(self):
        payload = {'articles': [{'text': 'synthetic sensitive content'}]}
        with self.store.connect() as connection:
            connection.execute('''CREATE TABLE runs (id TEXT PRIMARY KEY, request_key TEXT UNIQUE,
                payload TEXT NOT NULL, created_at TEXT NOT NULL, status TEXT NOT NULL, stage TEXT NOT NULL,
                result TEXT, error TEXT)''')
            connection.execute("INSERT INTO runs VALUES (?, ?, ?, ?, 'completed', 'export', NULL, NULL)",
                               ('old', 'old-key', json.dumps(payload), '2026-11-07T12:00:00+00:00'))
        self.store.prepare()
        self.assertEqual(('old', False), self.store.create(payload, 'old-key'))
        with self.assertRaises(RequestConflict):
            self.store.create({}, 'old-key')

        run_id, _ = self.store.create(payload, 'new-key')
        self.assertEqual((run_id, False), self.store.create(payload, 'new-key'))
        with self.store.connect() as connection:
            row = connection.execute('SELECT payload, expires_at FROM runs WHERE id=?', (run_id,)).fetchone()
        self.assertEqual(f'sha256:{json_sha256(payload)}', row['payload'])
        self.assertIsNotNone(row['expires_at'])

        with patch.dict(os.environ, NOTFIVE_MIGRATE_LEGACY_RUNS='1'):
            self.store.prepare()
        self.assertEqual(('old', False), self.store.create(payload, 'old-key'))
        with self.store.connect() as connection:
            migrated = connection.execute('SELECT payload, expires_at FROM runs WHERE id=?', ('old',)).fetchone()
        self.assertEqual(row['payload'], migrated['payload'])
        self.assertIsNotNone(migrated['expires_at'])

    def test_legacy_exports_are_checked_at_read_time(self):
        self.store.prepare()
        run_id, _ = self.store.create({}, None)
        result = {'events': [], 'artifacts': {'journal.csv': '=1+1'}, 'journal': [
            {'id': '=1+1', 'status': 'исключён', 'group': '', 'reason': '', 'error': ''},
        ]}
        self.store.update(run_id, status='completed', result=result)
        stored = self.store.get(run_id)['result']
        self.assertIn("'=1+1", stored['artifacts']['journal.csv'])
        self.assertEqual('=1+1', stored['journal'][0]['id'])

        result['events'] = [{'sources': [{'url': 'https://example.invalid?a=1&api_key=synthetic'}]}]
        self.store.update(run_id, status='completed', result=result)
        stored = self.store.get(run_id)
        self.assertEqual('failed', stored['status'])
        self.assertIsNone(stored['result'])
        self.assertNotIn('synthetic', stored['error'])

    def test_expired_result_is_hidden_then_purged(self):
        self.store.prepare()
        run_id, _ = self.store.create({}, 'expired')
        self.store.update(run_id, status='completed')
        with self.store.connect() as connection:
            connection.execute('UPDATE runs SET expires_at=? WHERE id=?', ('2000-01-01T00:00:00+00:00', run_id))
        self.assertIsNone(self.store.get(run_id))
        new_id, created = self.store.create({}, 'expired')
        self.assertTrue(created)
        self.assertNotEqual(run_id, new_id)
        with self.store.connect() as connection:
            self.assertIsNone(connection.execute('SELECT id FROM runs WHERE id=?', (run_id,)).fetchone())
