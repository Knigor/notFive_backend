<?php

declare(strict_types=1);

use App\Controller\RunArtifactController;
use App\Digest\DigestBuilder;
use App\Digest\DigestRenderer;
use App\Digest\DigestRepository;
use App\Import\ImportRepository;
use App\Run\GoRunDispatcher;
use App\Run\RunDiagnostics;
use App\Run\RunProcessor;
use Doctrine\DBAL\DriverManager;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;

require dirname(__DIR__).'/vendor/autoload.php';

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$pdo = $connection->getNativeConnection();
assert($pdo instanceof PDO);
$pdo->sqliteCreateFunction('gen_random_uuid', static fn (): string => bin2hex(random_bytes(16)));
$connection->executeStatement('CREATE TABLE runs (id TEXT PRIMARY KEY, control_time TEXT, status TEXT, settings_snapshot TEXT, created_at TEXT, started_at TEXT, finished_at TEXT, error TEXT, model_version TEXT, prompt_version TEXT)');
$connection->executeStatement('CREATE TABLE run_inputs (id TEXT PRIMARY KEY, run_id TEXT, kind TEXT, name TEXT, locator TEXT, sha256 TEXT, status TEXT, site_source_id TEXT, error TEXT)');
$connection->executeStatement('CREATE TABLE materials (id TEXT PRIMARY KEY, run_id TEXT, run_input_id TEXT, input_id TEXT, title TEXT, body TEXT, source_name TEXT, url TEXT, published_at TEXT, input_missing TEXT, source_error TEXT, journal_status TEXT, journal_group TEXT, journal_reason TEXT, journal_error TEXT, UNIQUE (run_input_id, input_id))');
$connection->executeStatement('CREATE TABLE events (id TEXT PRIMARY KEY, run_id TEXT, rank INTEGER, group_key TEXT, title TEXT, summary TEXT, consequences TEXT, selection_reason TEXT, post_text TEXT)');
$connection->executeStatement('CREATE TABLE event_materials (event_id TEXT, material_id TEXT, run_id TEXT, is_primary_source INTEGER)');
$connection->executeStatement('CREATE TABLE event_evidence (id TEXT, event_id TEXT, material_id TEXT, claim TEXT, source_excerpt TEXT, qualification TEXT)');
$connection->executeStatement('CREATE TABLE artifacts (id TEXT, run_id TEXT, kind TEXT, content TEXT)');
$connection->executeStatement('CREATE TABLE email_preview_details (artifact_id TEXT, subject TEXT)');
$connection->executeStatement('CREATE TABLE model_calls (id TEXT, run_id TEXT, stage TEXT, input_tokens INTEGER, output_tokens INTEGER, duration_ms INTEGER)');
$connection->executeStatement('CREATE TABLE dataset_import_errors (id TEXT, run_input_id TEXT, row_number INTEGER, input_id TEXT, error TEXT)');
$runId = '00000000-0000-4000-8000-000000000001';
$connection->insert('runs', ['id' => $runId, 'control_time' => '2026-11-06T10:00:00+03:00', 'status' => 'queued', 'settings_snapshot' => '{"dataset":"smoke"}', 'created_at' => '2026-11-06T10:00:00+03:00']);
$connection->insert('run_inputs', ['id' => 'input-1', 'run_id' => $runId, 'kind' => 'dataset', 'locator' => '/tmp/smoke.json', 'status' => 'completed']);
foreach ([
    ['A-1', 'Исправлена уязвимость', 'Производитель выпустил исправление уязвимости.', 'https://example.org/a-1', '2026-11-06T09:00:00+03:00'],
    ['A-2', 'Материал без даты', 'Дата публикации не указана.', 'https://example.org/a-2', null],
] as $index => [$id, $title, $body, $url, $publishedAt]) {
    $connection->insert('materials', [
        'id' => 'material-'.($index + 1), 'run_id' => $runId, 'run_input_id' => 'input-1',
        'input_id' => $id, 'title' => $title, 'body' => $body, 'source_name' => 'Example',
        'url' => $url, 'published_at' => $publishedAt, 'input_missing' => '[]', 'source_error' => null,
    ]);
}
$response = [
    'runId' => 'go-digest-test',
    'groups' => [[
        'id' => 'G1', 'name' => 'Исправление уязвимости', 'ids' => ['A-1'],
        'selectionReason' => 'Есть подтверждённое исправление.',
        'facts' => [['statement' => 'Вышло исправление.', 'sourceIds' => ['A-1'], 'evidence' => [['articleId' => 'A-1', 'quote' => 'Производитель выпустил исправление уязвимости.']]]],
        'contradictions' => [],
    ]],
    'recommendedOrder' => ['G1'],
    'excluded' => [['articleId' => 'A-2', 'reason' => 'Нет даты публикации.']],
    'events' => [[
        'groupId' => 'G1', 'title' => 'Исправлена уязвимость',
        'summary' => 'Производитель выпустил исправление. Дополнительные детали не опубликованы.',
        'consequences' => 'Исправление доступно пользователям.',
        'post' => 'Производитель выпустил исправление уязвимости.',
        'reason' => 'Есть подтверждённое исправление.',
    ]],
    'selectionSummary' => 'Найдено одно подходящее событие.', 'warnings' => [],
    'analysisModel' => 'fake-analysis', 'draftModel' => 'fake-draft',
    'analysisPromptVersion' => 'analysis-v1', 'draftPromptVersion' => 'draft-v1',
    'analysisUsageJson' => '{"input_tokens":12,"output_tokens":6}',
    'draftUsageJson' => '{"input_tokens":8,"output_tokens":4}',
    'analysisDurationMs' => 10, 'draftDurationMs' => 12,
];
$requests = [];
$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $response): MockResponse {
    $request = json_decode((string) $options['body'], true, 512, JSON_THROW_ON_ERROR);
    $requests[] = $request;
    if (str_contains($request['query'], 'mutation Collect')) {
        $articles = [
            ['id' => 'A-1', 'title' => 'Исправлена уязвимость', 'text' => 'Производитель выпустил исправление уязвимости.', 'source' => 'Example', 'url' => 'https://example.org/a-1', 'publishedAt' => '2026-11-06T09:00:00+03:00', 'missing' => [], 'error' => null],
            ['id' => 'A-2', 'title' => 'Материал без даты', 'text' => 'Дата публикации не указана.', 'source' => 'Example', 'url' => 'https://example.org/a-2', 'publishedAt' => null, 'missing' => [], 'error' => null],
        ];

        $articles[] = [...$articles[0], 'id' => 'BAD-HOST', 'url' => 'https://another.example/foreign'];
        $articles[] = [...$articles[0], 'id' => 'BAD-FIELD', 'title' => ['invalid'], 'url' => 'https://example.org/bad-field'];
        $articles[] = [...$articles[0], 'id' => 'BAD-DATE', 'publishedAt' => 'yesterday', 'url' => 'https://example.org/bad-date'];
        $collection = [
            'runId' => 'go-collection-test', 'sites' => ['https://example.org'], 'articles' => $articles,
            'excluded' => [['id' => 'REJECT-1', 'url' => 'https://example.org/old', 'reason' => 'За пределами периода.']],
            'warnings' => ['collection warning'],
            'researchJson' => json_encode([
                'candidate_audit' => [
                    ['id' => 'REJECT-1', 'url' => 'https://example.org/old', 'status' => 'excluded', 'reason' => 'За пределами периода.', 'error' => 'outside_period'],
                    ['id' => 'LIMIT-1', 'url' => 'https://example.org/limit', 'status' => 'not_processed', 'reason' => 'Достигнут лимит.', 'error' => 'fetch_limit'],
                ],
                'rejected_candidates' => [['url' => 'https://example.org/tags', 'reason' => 'Страница раздела.']],
                'citation_urls' => ['https://example.org/old', 'https://example.org/a-1#section', 'https://example.org/unprocessed'],
            ], JSON_THROW_ON_ERROR),
        ];

        return new MockResponse(json_encode(['data' => ['collect' => $collection]], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }
    $output = $response;
    if ([] === $request['variables']['input']['items']) {
        $output['groups'] = [];
        $output['recommendedOrder'] = [];
        $output['excluded'] = [];
        $output['events'] = [];
        $output['selectionSummary'] = '';
    }

    return new MockResponse(json_encode(['data' => ['prepareDigest' => $output]], JSON_THROW_ON_ERROR), ['http_code' => 200]);
});
$translator = new Translator('ru');
$translator->addLoader('yaml', new YamlFileLoader());
$translator->addResource('yaml', dirname(__DIR__).'/translations/messages.ru.yaml', 'ru');
$translator->addResource('yaml', dirname(__DIR__).'/translations/messages.en.yaml', 'en');
$repository = new DigestRepository($connection, new RunDiagnostics($translator));
$builder = new DigestBuilder($repository, new GoRunDispatcher($client, new NullLogger(), 'http://digest', 'test-token'), new DigestRenderer($repository, $translator), $connection, Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(), $translator, new NullLogger());
$logger = new class extends AbstractLogger {
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (isset($context['exception'])) {
            fwrite(STDERR, (string) $context['exception']);
        }
    }
};
$processor = new RunProcessor(new ImportRepository($connection), $repository, new GoRunDispatcher($client, new NullLogger(), 'http://digest', 'test-token'), $builder, Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(), $logger);
assert($processor->processNext());
$runStatus = $repository->status($runId);
assert(null !== $runStatus && 'completed' === $runStatus['status']);
assert(4 === count($runStatus['files']));
assert(1 === count($requests));
assert('A-1' === $requests[0]['variables']['input']['items'][0]['id']);
assert(!array_key_exists('file', $requests[0]['variables']['input']));
assert(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM events'));
assert(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM event_evidence'));
assert(4 === (int) $connection->fetchOne('SELECT COUNT(*) FROM artifacts'));
assert('included' === $connection->fetchOne("SELECT journal_status FROM materials WHERE input_id = 'A-1'"));
assert('excluded' === $connection->fetchOne("SELECT journal_status FROM materials WHERE input_id = 'A-2'"));
assert(12 === (int) $connection->fetchOne("SELECT input_tokens FROM model_calls WHERE stage = 'analysis'"));
assert(str_contains((string) $connection->fetchOne("SELECT content FROM artifacts WHERE kind = 'journal'"), 'A-2;исключён'));
$emptyRunId = '00000000-0000-4000-8000-000000000002';
$connection->insert('runs', ['id' => $emptyRunId, 'control_time' => '2026-11-06T10:00:00+03:00', 'status' => 'queued', 'settings_snapshot' => '{"dataset":"smoke"}', 'created_at' => '2026-11-06T10:01:00+03:00']);
$processor->processNext();
$emptyStatus = $repository->status($emptyRunId);
assert(null !== $emptyStatus && 'completed' === $emptyStatus['status']);
assert(4 === (int) $connection->fetchOne('SELECT COUNT(*) FROM artifacts WHERE run_id = :id', ['id' => $emptyRunId]));
assert(str_contains((string) $connection->fetchOne("SELECT content FROM artifacts WHERE run_id = :id AND kind = 'digest'", ['id' => $emptyRunId]), 'Подходящих событий за период нет.'));
$siteRunId = '00000000-0000-4000-8000-000000000003';
$connection->insert('runs', ['id' => $siteRunId, 'control_time' => '2026-11-06T10:00:00+03:00', 'status' => 'queued', 'settings_snapshot' => '{"source_urls":["https://example.org"]}', 'created_at' => '2026-11-06T10:02:00+03:00']);
$connection->insert('run_inputs', ['id' => 'input-3', 'run_id' => $siteRunId, 'kind' => 'site', 'name' => 'Example', 'locator' => 'https://example.org/news', 'status' => 'pending']);
$processor->processNext();
$siteStatus = $repository->status($siteRunId);
assert(null !== $siteStatus && 'completed' === $siteStatus['status']);
assert(4 === count($siteStatus['files']));
assert(2 === (int) $connection->fetchOne('SELECT COUNT(*) FROM materials WHERE run_id = :id', ['id' => $siteRunId]));
assert('completed' === $connection->fetchOne("SELECT status FROM run_inputs WHERE id = 'input-3'"));
$diagnostics = $siteStatus['diagnostics'];
assert('go-collection-test' === $diagnostics['collectionRunId']);
assert('go-digest-test' === $diagnostics['digestRunId']);
assert(['candidates' => 9, 'collected' => 5, 'imported' => 2, 'included' => 1, 'merged' => 0, 'excluded' => 8, 'events' => 1] === $diagnostics['counts']);
assert(['collection warning'] === $diagnostics['warnings']);
$rows = array_column($diagnostics['materials'], null, 'id');
assert('source_host_mismatch' === $rows['BAD-HOST']['error']);
assert('invalid_material' === $rows['BAD-FIELD']['error']);
assert('invalid_publication_date' === $rows['BAD-DATE']['error']);
assert('outside_period' === $rows['REJECT-1']['error']);
assert('collection' === $rows['LIMIT-1']['stage']);
assert('excluded' === $rows['LIMIT-1']['status']);
$siteCsv = $repository->artifacts($siteRunId)['journal.csv'];
assert(10 === count(explode("\n", trim($siteCsv))));
assert(1 === substr_count($siteCsv, 'REJECT-1;'));
assert(str_contains($siteCsv, 'BAD-DATE;исключён'));
assert(str_contains($siteCsv, 'not_processed'));
$oldDiagnostics = (new RunDiagnostics($translator))->build([
    'go_collection' => ['researchJson' => '{"citation_urls":["https://example.org/unknown"],"rejected_candidates":[{"url":"https://example.org/old","reason":"Old exclusion"}]}'],
], [], [], 0, 'en');
assert(null === $oldDiagnostics['collectionRunId'] && null === $oldDiagnostics['digestRunId']);
assert(2 === $oldDiagnostics['counts']['excluded']);
assert(str_contains($oldDiagnostics['materials'][1]['reason'], 'not processed'));
$brokenAudit = (new RunDiagnostics($translator))->build(['go_collection' => ['researchJson' => '{broken']], [], [], 0);
assert(0 === $brokenAudit['counts']['candidates']);
assert([] === $brokenAudit['materials']);
$sameUrlMaterials = $repository->materials($siteRunId);
$sameUrlMaterials[1]['url'] = $sameUrlMaterials[0]['url'];
$sameUrlAudit = (new RunDiagnostics($translator))->build([], $sameUrlMaterials, [], 1);
assert(2 === count($sameUrlAudit['materials']));
$legacyUrls = ['https://example.org/a-1'];
$legacyExcluded = [];
for ($index = 1; $index <= 20; ++$index) {
    $url = 'https://example.org/legacy-'.$index;
    $legacyUrls[] = $url;
    if ($index <= 11) {
        $legacyExcluded[] = ['id' => 'LEGACY-'.$index, 'url' => $url, 'reason' => 'Дата не подтверждена.'];
    }
}
$legacyAudit = (new RunDiagnostics($translator))->build([
    'go_collection' => ['articles' => [['id' => 'A-1', 'url' => $legacyUrls[0]]], 'excluded' => $legacyExcluded, 'researchJson' => json_encode(['citation_urls' => $legacyUrls], JSON_THROW_ON_ERROR)],
], [$repository->materials($siteRunId)[0]], [], 1, 'ru', true);
assert(21 === $legacyAudit['counts']['candidates']);
assert(20 === $legacyAudit['counts']['excluded']);
assert(9 === count(array_filter($legacyAudit['materials'], static fn (array $row): bool => 'not_processed' === $row['error'])));
$escapedUrls = (new RunDiagnostics($translator))->build(['go_collection' => ['researchJson' => json_encode(['citation_urls' => ['https://example.org/a%2Fb', 'https://example.org/a/b']], JSON_THROW_ON_ERROR)]], [], [], 0);
assert(2 === $escapedUrls['counts']['candidates']);
$missingImport = (new RunDiagnostics($translator))->build(['go_collection' => ['articles' => [['id' => 'LOST', 'url' => 'https://example.org/lost']]]], [], [], 0, 'ru', true);
assert('excluded' === $missingImport['materials'][0]['status']);
assert('collection_material_missing' === $missingImport['materials'][0]['error']);

$defaultRunId = '00000000-0000-4000-8000-000000000005';
$connection->insert('runs', ['id' => $defaultRunId, 'control_time' => '2026-11-06T10:00:00+03:00', 'status' => 'queued', 'settings_snapshot' => '{"source_urls":[]}', 'created_at' => '2026-11-06T10:02:30+03:00']);
$processor->processNext();
$defaultStatus = $repository->status($defaultRunId);
assert(null !== $defaultStatus && 'completed' === $defaultStatus['status']);
assert(4 === count($defaultStatus['files']));
assert(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM run_inputs WHERE run_id = :id', ['id' => $defaultRunId]));
$failedRunId = '00000000-0000-4000-8000-000000000004';
$connection->insert('runs', ['id' => $failedRunId, 'control_time' => '2026-11-06T10:00:00+03:00', 'status' => 'queued', 'settings_snapshot' => '{"source_urls":[]}', 'created_at' => '2026-11-06T10:03:00+03:00']);
$unavailableGo = new GoRunDispatcher($client, new NullLogger(), '', '');
$failedProcessor = new RunProcessor(new ImportRepository($connection), $repository, $unavailableGo, $builder, Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(), new NullLogger());
$failedProcessor->processNext();
$failedStatus = $repository->status($failedRunId);
assert(null !== $failedStatus && 'failed' === $failedStatus['status']);
assert([] === $failedStatus['files']);
assert((new ImportRepository($connection))->requeue($failedRunId, false));
$requeuedStatus = $repository->status($failedRunId);
assert(null !== $requeuedStatus && 'queued' === $requeuedStatus['status']);
$downloads = new RunArtifactController($repository);
assert(str_contains((string) $downloads->file($runId, 'digest.md')->getContent(), 'Исправлена уязвимость'));
$archiveResponse = $downloads->archive($runId);
assert($archiveResponse instanceof BinaryFileResponse);
$archive = new ZipArchive();
assert(true === $archive->open($archiveResponse->getFile()->getPathname()));
assert(4 === $archive->numFiles);
assert(str_contains((string) $archive->getFromName('journal.csv'), 'A-2;исключён'));
$archive->close();
$directory = sys_get_temp_dir().'/notfive-digest-'.bin2hex(random_bytes(6));
mkdir($directory, 0700);
foreach (['digest' => 'digest.md', 'post' => 'post.md', 'email_preview' => 'email.md', 'journal' => 'journal.csv'] as $kind => $filename) {
    file_put_contents($directory.'/'.$filename, (string) $connection->fetchOne('SELECT content FROM artifacts WHERE run_id = :run_id AND kind = :kind', ['run_id' => $runId, 'kind' => $kind]));
}
file_put_contents($directory.'/news.json', json_encode([
    'control_time' => '2026-11-06T10:00:00+03:00',
    'period_start' => '2026-11-05T10:00:00+03:00',
    'timezone' => 'Europe/Moscow',
    'items' => [
        ['id' => 'A-1', 'title' => 'Исправлена уязвимость', 'text' => 'Производитель выпустил исправление уязвимости.', 'source' => 'Example', 'url' => 'https://example.org/a-1', 'published_at' => '2026-11-06T09:00:00+03:00'],
        ['id' => 'A-2', 'title' => 'Материал без даты', 'text' => 'Дата публикации не указана.', 'source' => 'Example', 'url' => 'https://example.org/a-2', 'published_at' => null],
    ],
], JSON_THROW_ON_ERROR));

// A relevant group may remain outside the selected digest; keep the model's reason.
$selectionRunId = '00000000-0000-4000-8000-000000000006';
$connection->insert('runs', ['id' => $selectionRunId, 'control_time' => '2026-11-06T10:00:00+03:00', 'status' => 'queued', 'settings_snapshot' => '{"dataset":"selection-smoke"}', 'created_at' => '2026-11-06T10:04:00+03:00']);
$connection->insert('run_inputs', ['id' => 'input-6', 'run_id' => $selectionRunId, 'kind' => 'dataset', 'locator' => '/tmp/selection-smoke.json', 'status' => 'completed']);
foreach ($repository->materials($runId) as $material) {
    $connection->insert('materials', [...$material, 'id' => 'selection-'.$material['id'], 'run_id' => $selectionRunId, 'run_input_id' => 'input-6']);
}
$connection->insert('materials', [
    'id' => 'selection-material-3', 'run_id' => $selectionRunId, 'run_input_id' => 'input-6', 'input_id' => 'A-3',
    'title' => 'Новая защитная мера', 'body' => 'Защитная мера вступит в силу через год.', 'source_name' => 'Example',
    'url' => 'https://example.org/a-3', 'published_at' => '2026-11-06T08:00:00+03:00', 'input_missing' => '[]', 'source_error' => null,
]);
$unselectedReason = 'Защитная мера относится к ИБ, но исправление уже используемой уязвимости требует более срочного внимания.';
$selectionResponse = $response;
$selectionResponse['groups'][] = [
    'id' => 'G2', 'name' => 'Новая защитная мера', 'ids' => ['A-3'], 'selectionReason' => $unselectedReason,
    'facts' => [['statement' => 'Мера начнёт действовать через год.', 'sourceIds' => ['A-3'], 'evidence' => [['articleId' => 'A-3', 'quote' => 'Защитная мера вступит в силу через год.']]]], 'contradictions' => [],
];
$selectionClient = new MockHttpClient(new MockResponse(json_encode(['data' => ['prepareDigest' => $selectionResponse]], JSON_THROW_ON_ERROR)));
$selectionBuilder = new DigestBuilder($repository, new GoRunDispatcher($selectionClient, new NullLogger(), 'http://digest', 'test-token'), new DigestRenderer($repository, $translator), $connection, Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(), $translator, new NullLogger());
$selectionBuilder->build($selectionRunId);
assert('excluded' === $connection->fetchOne("SELECT journal_status FROM materials WHERE id = 'selection-material-3'"));
assert($unselectedReason === $connection->fetchOne("SELECT journal_reason FROM materials WHERE id = 'selection-material-3'"));
assert('Нет даты публикации.' === $connection->fetchOne("SELECT journal_reason FROM materials WHERE id = 'selection-material-2'"));
assert(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM events WHERE run_id = :id', ['id' => $selectionRunId]));
assert(str_contains((string) $connection->fetchOne("SELECT content FROM artifacts WHERE run_id = :id AND kind = 'journal'", ['id' => $selectionRunId]), $unselectedReason));
echo "Digest build smoke passed. Output: $directory\n";
