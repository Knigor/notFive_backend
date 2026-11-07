const fs = require('node:fs/promises');
const path = require('node:path');
const NotFiveProvider = require('./provider.cjs');
const check = require('./checks.cjs');
const { compareRuns } = require('./reproducibility.cjs');
const fixtureFile = require('../../docs/case/fixtures/real-site-cases.json');

const root = path.resolve(__dirname, '../..');
const duplicateCases = ['duplicate', 'duplicate_repeat'];
const cases = process.argv.includes('--repeat-only') ? duplicateCases : [...Object.keys(fixtureFile.cases), 'duplicate_repeat'];
const snapshots = [
  'docs/case/fixtures/real-site-cases.json',
  'docs/case/fixtures/notfive-extract.yawl.yaml',
  ...['__init__', 'app', 'domain', 'exports', 'fingerprints', 'pipeline', 'prompts', 'provider', 'retrieval', 'store']
    .map((name) => `notfive_py/${name}.py`),
  'notfive_py/glossary.txt',
  'notfive_py/requirements.txt',
  ...['checks.cjs', 'provider.cjs', 'run.cjs', 'reproducibility.cjs', 'promptfooconfig.yaml']
    .map((name) => `notfive_py/evals/${name}`),
];

async function main() {
  const startedAt = new Date().toISOString();
  const destination = path.join(root, 'var', 'notfive-evals', startedAt.replaceAll(':', '-'));
  const snapshot = path.join(destination, 'snapshot');
  for (const name of snapshots) {
    const target = path.join(snapshot, name);
    await fs.mkdir(path.dirname(target), { recursive: true });
    await fs.copyFile(path.join(root, name), target);
  }
  console.log(destination);

  const provider = new NotFiveProvider();
  const results = [];
  const duplicateRuns = new Map();
  for (const caseId of cases) {
    const context = { vars: { caseId } };
    const scenario = fixtureFile.cases[caseId.replace(/_repeat$/, '')];
    const startedAt = new Date().toISOString();
    const response = await provider.callApi(caseId, context);
    const grade = response.error ? { pass: false, score: 0, reason: response.error } : check(response.output, context);
    const blocked = Boolean(response.error || response.output?.error?.includes('Связь с Yandex Cloud прервалась.'));
    const verdict = blocked ? 'blocked' : grade.pass ? 'pass' : 'fail';
    const evidence = { caseId, startedAt, ...scenario, response, grade, verdict };
    if (duplicateCases.includes(caseId)) duplicateRuns.set(caseId, evidence);
    await fs.writeFile(path.join(destination, `${caseId}.json`), JSON.stringify(evidence, null, 2) + '\n');
    results.push({
      caseId, startedAt, id: response.output?.id ?? null,
      status: response.output?.status ?? 'transport_error', verdict, ...grade,
    });
    console.log(`${verdict.toUpperCase()} ${caseId} ${grade.reason}`);
  }
  const passed = results.filter((result) => result.verdict === 'pass').length;
  const failed = results.filter((result) => result.verdict === 'fail').length;
  const reproducibility = compareRuns(duplicateRuns.get('duplicate'), duplicateRuns.get('duplicate_repeat'));
  console.log(`${reproducibility.verdict.toUpperCase()} reproducibility ${reproducibility.reason}`);
  await fs.writeFile(path.join(destination, 'summary.json'), JSON.stringify({
    startedAt, finishedAt: new Date().toISOString(), runner: `Node.js ${process.version}`, passed,
    failed, blocked: results.length - passed - failed, reproducibility, results,
  }, null, 2) + '\n');
  process.exitCode = passed === results.length && reproducibility.verdict === 'pass' ? 0 : 1;
}

main().catch((error) => {
  console.error(error.message);
  process.exitCode = 1;
});
