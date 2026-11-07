const { createHash } = require('node:crypto');
const { isDeepStrictEqual } = require('node:util');

const uniqueSorted = (items) => [...new Set(items)].sort();

function byId(left, right) {
  if (left.id === right.id) return 0;
  return left.id < right.id ? -1 : 1;
}

function byteCounts(content) {
  const measurement = /(?<![.,])\b(\d+(?:[ \u00a0\u202f]\d{3})*(?:[.,]\d+)?)[ \u00a0\u202f\u2010-\u2013-]+(?:байт[а-яё]*|bytes?\b)/giu;
  return uniqueSorted(Array.from(content.matchAll(measurement),
    (match) => match[1].replace(/\s/g, '')));
}

function signature(result) {
  return {
    period: [result.control_time, result.period?.start_exclusive, result.period?.end_inclusive, result.period?.timezone],
    outcome: result.outcome,
    events: (result.events ?? []).map((event) => {
      const facts = event.facts ?? [];
      const published = [event.title, event.summary, event.post, event.consequences].join(' ');
      const content = [published, ...facts.map((fact) => fact.evidence)].join(' ');
      return {
        rank: event.rank,
        cves: uniqueSorted(content.toUpperCase().match(/\bCVE-\d{4}-\d{4,}\b/g) ?? []),
        byte_counts: byteCounts(published),
        sources: (event.sources ?? []).map(({ id, url, published_at }) => ({ id, url, published_at })).sort(byId),
        evidence_source_ids: uniqueSorted(facts.map((fact) => fact.source_id)),
      };
    }),
    journal: (result.journal ?? []).map(({ id, status, group, error }) => ({
      id, status, group, has_error: Boolean(error),
    })).sort(byId),
  };
}

function stageValues(result, stage, field) {
  return uniqueSorted((result.model_calls ?? []).filter((call) => call.stage === stage && Object.hasOwn(call, field) &&
    (field !== 'request_sha256' || call.attempt === undefined || call.attempt === 1))
    .map((call) => JSON.stringify(call[field])));
}

function compareRuns(initial, repeat) {
  const left = initial?.response?.output;
  const right = repeat?.response?.output;
  const details = {
    case_ids: [initial?.caseId ?? null, repeat?.caseId ?? null],
    run_ids: [left?.id ?? null, right?.id ?? null],
  };
  if (!left?.id || !right?.id || left.status !== 'completed' || right.status !== 'completed' || !left.result || !right.result) {
    return { ...details, verdict: 'blocked', reason: 'Для сравнения нужны два завершённых запуска с результатом.' };
  }
  if (left.id === right.id) {
    return { ...details, verdict: 'fail', reason: 'API вернул один и тот же ID. Повторный вызов модели не подтверждён.' };
  }
  details.model_call_counts = [left.result.model_calls?.length ?? 0, right.result.model_calls?.length ?? 0];
  if (details.model_call_counts.includes(0)) {
    return { ...details, verdict: 'blocked', reason: 'Нет сведений о модельных вызовах в одном или обоих запусках.' };
  }

  const before = signature(left.result);
  const after = signature(right.result);
  details.signature_sha256 = [before, after].map((value) => createHash('sha256').update(JSON.stringify(value)).digest('hex'));
  details.different_sections = Object.keys(before).filter((key) => !isDeepStrictEqual(before[key], after[key]));
  details.provenance_changes = ['input_sha256', 'prompt_version'].filter((field) =>
    left.result[field] != null && right.result[field] != null && left.result[field] !== right.result[field]);
  details.changed_downstream_requests = [];

  const stages = uniqueSorted([...(left.result.model_calls ?? []), ...(right.result.model_calls ?? [])].map((call) => call.stage));
  const compareFields = ['requested_model', 'model', 'temperature', 'max_tokens', 'seed', 'system_fingerprint', 'request_sha256'];
  for (const stage of stages) {
    for (const field of compareFields) {
      const a = stageValues(left.result, stage, field);
      const b = stageValues(right.result, stage, field);
      if (!a.length || !b.length || isDeepStrictEqual(a, b)) continue;
      if (field === 'request_sha256' && stage !== 'extract' && stage !== 'workflow_extract') {
        details.changed_downstream_requests.push(stage);
      } else {
        details.provenance_changes.push(`${stage}.${field}`);
      }
    }
  }

  if (details.provenance_changes.length) {
    return { ...details, verdict: 'blocked', reason: 'Данные или конфигурация модели различаются между запусками.' };
  }
  if (!initial.grade?.pass || !repeat.grade?.pass) {
    return { ...details, verdict: 'fail', reason: 'Один или оба запуска не прошли проверку сценария.' };
  }
  if (details.different_sections.length) {
    return { ...details, verdict: 'fail', reason: 'Структурированные факты или решения по статьям изменились.' };
  }
  return { ...details, verdict: 'pass', reason: 'События, источники и решения по статьям совпали.' };
}

module.exports = { byteCounts, compareRuns };
