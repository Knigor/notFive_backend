<?php

declare(strict_types=1);

use App\Digest\DigestBuilder;
use App\Digest\DigestRenderer;
use App\Digest\DigestRepository;
use App\Run\GoRunDispatcher;
use Doctrine\DBAL\DriverManager;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;

require dirname(__DIR__).'/vendor/autoload.php';

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$pdo = $connection->getNativeConnection();
assert($pdo instanceof PDO);
$pdo->sqliteCreateFunction('gen_random_uuid', static fn (): string => bin2hex(random_bytes(16)));
$connection->executeStatement('CREATE TABLE runs (id TEXT PRIMARY KEY, control_time TEXT, status TEXT, settings_snapshot TEXT, started_at TEXT, finished_at TEXT, error TEXT, model_version TEXT, prompt_version TEXT)');
$connection->executeStatement('CREATE TABLE run_inputs (id TEXT PRIMARY KEY, run_id TEXT)');
$connection->executeStatement('CREATE TABLE materials (id TEXT PRIMARY KEY, run_id TEXT, run_input_id TEXT, input_id TEXT, title TEXT, body TEXT, source_name TEXT, url TEXT, published_at TEXT, input_missing TEXT, source_error TEXT, journal_status TEXT, journal_group TEXT, journal_reason TEXT, journal_error TEXT)');
$connection->executeStatement('CREATE TABLE events (id TEXT PRIMARY KEY, run_id TEXT, rank INTEGER, group_key TEXT, title TEXT, summary TEXT, consequences TEXT, selection_reason TEXT, post_text TEXT)');
$connection->executeStatement('CREATE TABLE event_materials (event_id TEXT, material_id TEXT, run_id TEXT, is_primary_source INTEGER)');
$connection->executeStatement('CREATE TABLE event_evidence (id TEXT, event_id TEXT, material_id TEXT, claim TEXT, source_excerpt TEXT, qualification TEXT)');
$connection->executeStatement('CREATE TABLE artifacts (id TEXT, run_id TEXT, kind TEXT, content TEXT)');
$connection->executeStatement('CREATE TABLE email_preview_details (artifact_id TEXT, subject TEXT)');
$connection->executeStatement('CREATE TABLE model_calls (id TEXT, run_id TEXT, stage TEXT, input_tokens INTEGER, output_tokens INTEGER, duration_ms INTEGER)');
$connection->executeStatement('CREATE TABLE dataset_import_errors (id TEXT, run_input_id TEXT, row_number INTEGER, input_id TEXT, error TEXT)');
$runId = '00000000-0000-4000-8000-000000000001';
$connection->insert('runs', ['id' => $runId, 'control_time' => '2026-11-06T10:00:00+03:00', 'status' => 'queued', 'settings_snapshot' => '{}']);
$connection->insert('run_inputs', ['id' => 'input-1', 'run_id' => $runId]);
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
$repository = new DigestRepository($connection);
$builder = new DigestBuilder($repository, new GoRunDispatcher($client, new NullLogger(), 'http://digest', 'test-token'), new DigestRenderer($repository, $translator), $connection, Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(), $translator);
$result = $builder->build($runId);
assert('completed' === $result['status'] && 1 === $result['eventCount']);
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
$connection->insert('runs', ['id' => $emptyRunId, 'control_time' => '2026-11-06T10:00:00+03:00', 'status' => 'queued', 'settings_snapshot' => '{}']);
$empty = $builder->build($emptyRunId);
assert('completed' === $empty['status'] && 0 === $empty['eventCount']);
assert(4 === (int) $connection->fetchOne('SELECT COUNT(*) FROM artifacts WHERE run_id = :id', ['id' => $emptyRunId]));
assert(str_contains((string) $connection->fetchOne("SELECT content FROM artifacts WHERE run_id = :id AND kind = 'digest'", ['id' => $emptyRunId]), 'Подходящих событий за период нет.'));
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
echo "Digest build smoke passed. Output: $directory\n";
