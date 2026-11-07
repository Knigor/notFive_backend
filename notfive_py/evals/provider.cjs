const { randomUUID } = require('node:crypto');
const { readFile } = require('node:fs/promises');
const path = require('node:path');
const { setTimeout: delay } = require('node:timers/promises');

const baseUrl = 'http://127.0.0.1:8090';
const fixturesPath = path.resolve(__dirname, '../../docs/case/fixtures/real-site-cases.json');

async function requestJson(endpoint, options) {
  const token = process.env.NOTFIVE_API_TOKEN?.trim();
  if (!token || !/^[\x21-\x7e]+$/.test(token)) {
    throw new Error('Задайте NOTFIVE_API_TOKEN для подключения к notFive.');
  }
  const response = await fetch(`${baseUrl}${endpoint}`, {
    ...options, headers: { ...options.headers, Authorization: `Bearer ${token}` }, redirect: 'error',
  });
  if (!response.ok) {
    throw new Error(`${options.method || 'GET'} ${endpoint} вернул HTTP ${response.status}`);
  }
  return response.json();
}

module.exports = class NotFiveProvider {
  id() {
    return 'notfive-local-api';
  }

  async callApi(_prompt, context) {
    const signal = AbortSignal.timeout(180_000);
    try {
      const caseId = context?.vars?.caseId;
      if (typeof caseId !== 'string') throw new Error('Укажите caseId сценария.');
      const { cases } = JSON.parse(await readFile(fixturesPath, 'utf8'));
      const key = caseId.replace(/_repeat$/, '');
      if (!Object.hasOwn(cases, key)) throw new Error(`Неизвестный сценарий ${caseId}.`);

      const endpoint = '/api/v1/runs';
      const started = await requestJson(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Idempotency-Key': randomUUID() },
        body: JSON.stringify(cases[key].input),
        signal,
      });
      const runId = started?.id;
      if (typeof runId !== 'string' || !runId) throw new Error('API не вернул ID запуска.');

      while (!signal.aborted) {
        const run = await requestJson(`${endpoint}/${encodeURIComponent(runId)}`, { signal });
        if (run?.id !== runId) throw new Error('API вернул данные другого запуска.');
        if (run.status === 'completed' || run.status === 'failed') return { output: run };
        if (run.status !== 'queued' && run.status !== 'running') {
          throw new Error('API вернул неизвестный статус запуска.');
        }
        await delay(750, undefined, { signal });
      }
      signal.throwIfAborted();
    } catch (error) {
      return { error: signal.aborted ? 'Истекло время ожидания запуска notFive.' : String(error.message || error) };
    }
  }
};
