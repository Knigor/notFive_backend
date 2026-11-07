const assert = require('node:assert/strict');
const { randomBytes } = require('node:crypto');
const test = require('node:test');

const NotFiveProvider = require('./provider.cjs');
const { compareRuns } = require('./reproducibility.cjs');

function completed(id) {
  const result = {
    control_time: '2026-04-30T18:00:00+03:00',
    period: { start_exclusive: '2026-04-29T18:00:00+03:00', end_inclusive: '2026-04-30T18:00:00+03:00', timezone: 'Europe/Moscow' },
    outcome: 'nonempty',
    input_sha256: 'input',
    prompt_version: 'test-v1',
    model_calls: [
      { stage: 'extract', requested_model: 'model', request_sha256: 'extract-request', system_fingerprint: 'backend', temperature: 0.1 },
      { stage: 'audit', requested_model: 'model', request_sha256: 'audit-request', system_fingerprint: 'backend', temperature: 0.1 },
    ],
    events: [{
      rank: 1, summary: 'Локальный пользователь может повысить привилегии до root.',
      sources: [{ id: 'a', url: 'https://example.invalid/a' }, { id: 'b', url: 'https://example.invalid/b' }],
      facts: [{ source_id: 'a', evidence: 'Описана CVE-2026-31431.' }, { source_id: 'b', evidence: 'CVE-2026-31431.' }],
    }],
    journal: [{ id: 'a', status: 'включён', group: '1' }, { id: 'b', status: 'объединён', group: '1' }],
  };
  return { response: { output: { id, status: 'completed', result } }, grade: { pass: true } };
}

test('allows paraphrases and changed downstream request hashes', () => {
  const a = completed('initial');
  const b = completed('repeat');
  const event = b.response.output.result.events[0];
  event.summary = 'Уязвимость позволяет локально получить root.';
  event.sources.reverse();
  event.facts[0].evidence = 'CVE-2026-31431';
  b.response.output.result.model_calls[1].request_sha256 = 'changed-by-model-output';
  const verdict = compareRuns(a, b);
  assert.equal(verdict.verdict, 'pass');
  assert.deepEqual(verdict.changed_downstream_requests, ['audit']);
  assert.equal(verdict.signature_sha256[0], verdict.signature_sha256[1]);
});

test('finds a changed source and journal group', () => {
  const a = completed('initial');
  const b = completed('repeat');
  b.response.output.result.events[0].sources[1].url = 'https://example.invalid/other';
  b.response.output.result.journal[1].group = '2';
  const verdict = compareRuns(a, b);
  assert.equal(verdict.verdict, 'fail');
  assert.deepEqual(verdict.different_sections, ['events', 'journal']);
});

test('compares numeric evidence across different phrasing', () => {
  const a = completed('initial');
  const b = completed('repeat');
  a.response.output.result.events[0].post = 'Для теста применили 732 байта кода.';
  b.response.output.result.events[0].post = 'Использовали 732-байтовый пример.';
  assert.equal(compareRuns(a, b).verdict, 'pass');
  b.response.output.result.events[0].post = 'Использовали 731-байтовый пример.';
  assert.deepEqual(compareRuns(a, b).different_sections, ['events']);
});

test('requires independent, passing runs with comparable inputs and provider settings', () => {
  const a = completed('initial');
  const b = completed('repeat');
  assert.equal(compareRuns(a, a).verdict, 'fail');
  assert.equal(compareRuns(a, undefined).verdict, 'blocked');
  b.grade.pass = false;
  assert.equal(compareRuns(a, b).verdict, 'fail');
  b.grade.pass = true;
  b.response.output.result.input_sha256 = 'different-input';
  b.response.output.result.model_calls[0].system_fingerprint = 'different-backend';
  b.response.output.result.model_calls[0].request_sha256 = 'different-request';
  const verdict = compareRuns(a, b);
  assert.equal(verdict.verdict, 'blocked');
  assert.deepEqual(verdict.provenance_changes, ['input_sha256', 'extract.system_fingerprint', 'extract.request_sha256']);
});

test('requires model call metadata and compares a disabled seed with a set seed', () => {
  const a = completed('initial');
  const b = completed('repeat');
  a.response.output.result.model_calls[0].seed = null;
  b.response.output.result.model_calls[0].seed = 42;
  assert.deepEqual(compareRuns(a, b).provenance_changes, ['extract.seed']);
  b.response.output.result.model_calls = [];
  assert.equal(compareRuns(a, b).verdict, 'blocked');
});

test('uses the local API token on submission and polling without changing the payload', async (t) => {
  const token = randomBytes(32).toString('hex');
  const previous = process.env.NOTFIVE_API_TOKEN;
  process.env.NOTFIVE_API_TOKEN = token;
  t.after(() => {
    if (previous === undefined) delete process.env.NOTFIVE_API_TOKEN;
    else process.env.NOTFIVE_API_TOKEN = previous;
  });
  const requests = [];
  const runIds = [];
  t.mock.method(globalThis, 'fetch', async (url, options) => {
    assert.equal(options.headers.Authorization, `Bearer ${token}`);
    assert.equal(options.redirect, 'error');
    requests.push(options);
    if (options.method === 'POST') {
      assert.equal(url, 'http://127.0.0.1:8090/api/v1/runs');
      const id = `run-${runIds.length}`;
      runIds.push(id);
      return { ok: true, json: async () => ({ id }) };
    }
    const id = runIds.at(-1);
    assert.equal(url, `http://127.0.0.1:8090/api/v1/runs/${id}`);
    return { ok: true, json: async () => ({ id, status: 'completed' }) };
  });
  const provider = new NotFiveProvider();
  const a = await provider.callApi('', { vars: { caseId: 'duplicate' } });
  const b = await provider.callApi('', { vars: { caseId: 'duplicate_repeat' } });
  assert.equal(requests.length, 4);
  assert.equal(requests[0].body, requests[2].body);
  assert.notEqual(requests[0].headers['Idempotency-Key'], requests[2].headers['Idempotency-Key']);
  assert.notEqual(a.output.id, b.output.id);
  assert.ok(!JSON.stringify([a, b]).includes(token));
});
