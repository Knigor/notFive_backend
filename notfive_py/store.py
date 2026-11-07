from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
import json
import os
from pathlib import Path
import sqlite3
from uuid import uuid4

from .domain import url_has_credentials
from .exports import render_journal
from .fingerprints import json_sha256


class QueueFull(Exception):
    pass


class RequestConflict(Exception):
    pass


def stored_fingerprint(payload: str) -> str:
    if payload.startswith('sha256:'):
        return payload
    return f'sha256:{json_sha256(json.loads(payload))}'


class RunStore:
    def __init__(self, path: Path):
        self.path = path
        try:
            self.retention_days = int(os.getenv('NOTFIVE_RUN_RETENTION_DAYS', '30'))
        except ValueError:
            raise ValueError('NOTFIVE_RUN_RETENTION_DAYS должен быть целым числом от 1 до 365.') from None
        if not 1 <= self.retention_days <= 365:
            raise ValueError('NOTFIVE_RUN_RETENTION_DAYS должен быть целым числом от 1 до 365.')

    @contextmanager
    def connect(self):
        connection = sqlite3.connect(self.path, timeout=5)
        connection.row_factory = sqlite3.Row
        try:
            with connection:
                yield connection
        finally:
            connection.close()

    def prepare(self):
        self.path.parent.mkdir(parents=True, exist_ok=True)
        now = datetime.now(timezone.utc)
        with self.connect() as connection:
            connection.execute('''CREATE TABLE IF NOT EXISTS runs (
                id TEXT PRIMARY KEY, request_key TEXT UNIQUE, payload TEXT NOT NULL,
                created_at TEXT NOT NULL, status TEXT NOT NULL, stage TEXT NOT NULL,
                result TEXT, error TEXT, expires_at TEXT
            )''')
            columns = {row['name'] for row in connection.execute('PRAGMA table_info(runs)')}
            if 'expires_at' not in columns:
                connection.execute('ALTER TABLE runs ADD COLUMN expires_at TEXT')
            connection.execute("UPDATE runs SET status='failed', error=? WHERE status IN ('queued', 'running')",
                               ('Работа прервалась при перезапуске сервиса. Запусти обработку заново.',))
            if os.getenv('NOTFIVE_MIGRATE_LEGACY_RUNS') == '1':
                connection.execute('PRAGMA secure_delete=ON')
                legacy = connection.execute('SELECT id, payload FROM runs WHERE expires_at IS NULL').fetchall()
                expires = (now + timedelta(days=self.retention_days)).isoformat()
                connection.executemany('UPDATE runs SET payload=?, expires_at=? WHERE id=?',
                                       ((stored_fingerprint(row['payload']), expires, row['id']) for row in legacy))
            self.purge_expired(connection, now.isoformat())

    @staticmethod
    def purge_expired(connection, now: str):
        connection.execute("DELETE FROM runs WHERE expires_at <= ? AND status IN ('completed', 'failed')", (now,))

    def create(self, payload: dict, request_key: str | None) -> tuple[str, bool]:
        fingerprint = f'sha256:{json_sha256(payload)}'
        now = datetime.now(timezone.utc)
        with self.connect() as connection:
            connection.execute('BEGIN IMMEDIATE')
            self.purge_expired(connection, now.isoformat())
            if request_key:
                prior = connection.execute('SELECT id, payload FROM runs WHERE request_key=?', (request_key,)).fetchone()
                if prior:
                    if stored_fingerprint(prior['payload']) != fingerprint:
                        raise RequestConflict
                    return prior['id'], False
            pending = connection.execute("SELECT COUNT(*) FROM runs WHERE status IN ('queued','running')").fetchone()[0]
            if pending >= 5:
                raise QueueFull
            run_id = uuid4().hex
            expires_at = (now + timedelta(days=self.retention_days)).isoformat()
            connection.execute("""INSERT INTO runs (id, request_key, payload, created_at, status, stage, expires_at)
                               VALUES (?, ?, ?, ?, 'queued', 'input', ?)""",
                               (run_id, request_key, fingerprint, now.isoformat(), expires_at))
            return run_id, True

    def update(self, run_id: str, *, status: str, stage: str | None = None, result: dict | None = None, error: str | None = None):
        with self.connect() as connection:
            connection.execute('UPDATE runs SET status=?, stage=COALESCE(?,stage), result=?, error=? WHERE id=?',
                               (status, stage, json.dumps(result, ensure_ascii=False) if result is not None else None, error, run_id))

    def get(self, run_id: str) -> dict | None:
        with self.connect() as connection:
            row = connection.execute('''SELECT id, created_at, status, stage, result, error FROM runs
                WHERE id=? AND (expires_at IS NULL OR expires_at > ? OR status IN ('queued', 'running'))''',
                (run_id, datetime.now(timezone.utc).isoformat())).fetchone()
        if row is None:
            return None
        result = dict(row)
        if result['result']:
            data = json.loads(result['result'])
            if any(url_has_credentials(source.get('url')) for event in data.get('events', ()) for source in event['sources']):
                result.update(status='failed', result=None, error='Сохранённый выпуск содержит ссылку с параметрами доступа.')
            else:
                if 'artifacts' in data:
                    data['artifacts']['journal.csv'] = render_journal(data['journal'])
                result['result'] = data
        return result
