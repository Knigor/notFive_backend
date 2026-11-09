<?php

declare(strict_types=1);

use App\Digest\DigestRepository;
use App\GraphQL\Query\RunStatisticsQuery;
use App\Run\RunDiagnostics;
use App\Run\RunStatistics;
use App\Run\RunStatisticsAuditReader;
use App\Security\RequireAdmin;
use Doctrine\DBAL\DriverManager;
use Overblog\GraphQLBundle\Error\UserError;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

require dirname(__DIR__).'/vendor/autoload.php';

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
$connection->executeStatement('CREATE TABLE runs (id TEXT, control_time TEXT, status TEXT, created_at TEXT, started_at TEXT, settings_snapshot TEXT)');
$connection->executeStatement('CREATE TABLE materials (id TEXT, run_id TEXT, input_id TEXT, title TEXT, body TEXT, source_name TEXT, url TEXT, published_at TEXT, input_missing TEXT, source_error TEXT, journal_status TEXT, journal_group TEXT, journal_reason TEXT, journal_error TEXT)');
$connection->executeStatement('CREATE TABLE events (run_id TEXT)');
$connection->executeStatement('CREATE TABLE run_inputs (id TEXT, run_id TEXT)');
$connection->executeStatement('CREATE TABLE dataset_import_errors (id TEXT, run_input_id TEXT, row_number INT, input_id TEXT, error TEXT)');
$connection->executeStatement('CREATE TABLE model_calls (id TEXT, run_id TEXT, stage TEXT, duration_ms INT, input_tokens INT, output_tokens INT)');
$translator = new Translator('ru');
$translator->addLoader('yaml', new YamlFileLoader());
foreach (['ru', 'en'] as $locale) {
    $translator->addResource('yaml', dirname(__DIR__).'/translations/messages.'.$locale.'.yaml', $locale);
}
$runId = '00000000-0000-4000-8000-000000000001';
$collectionId = str_repeat('a', 32);
$digestId = str_repeat('b', 32);
$generation = ['model' => 'test-model', 'response_id' => 'search-response-1', 'started_at' => '2026-11-09T09:00:00Z', 'duration_ms' => 0, 'usage' => ['input_tokens' => 0, 'output_tokens' => 7], 'trace' => ['output' => [['id' => 'tool-1', 'type' => 'web_search_call'], ['id' => 'tool-2', 'type' => 'web_search_call']]], 'text' => 'PRIVATE_MODEL_OUTPUT'];
$research = [
    'generation' => $generation,
    'search_attempts' => [['generation' => $generation]],
    'search_calls' => $generation['trace']['output'],
    'discovery' => [['url' => 'https://example.org/feed', 'kind' => 'rss', 'found' => 5, 'outside_window' => 2, 'inspected_links' => 8, 'uninspected_links' => 0], ['url' => 'https://other.example/news', 'kind' => 'html', 'error' => 'PRIVATE_PROVIDER_ERROR']],
    'candidate_audit' => [['id' => 'L1', 'url' => 'https://example.org/limit', 'status' => 'not_processed', 'error' => 'fetch_limit']],
    'excluded_sources' => [['id' => 'L1', 'url' => 'https://example.org/limit', 'error' => 'fetch_limit']],
];
$settings = ['source_urls' => ['https://example.org'], 'go_digest_run_id' => $digestId, 'go_collection' => ['runId' => $collectionId, 'researchJson' => json_encode($research, JSON_THROW_ON_ERROR), 'warnings' => ['PRIVATE_WARNING']]];
$connection->insert('runs', ['id' => $runId, 'control_time' => '2026-11-09T09:00:00Z', 'status' => 'completed', 'created_at' => '2026-11-09T09:00:00Z', 'started_at' => '2026-11-09T09:00:01.250Z', 'settings_snapshot' => json_encode($settings, JSON_THROW_ON_ERROR)]);
foreach ([['outside_period', null], [null, 'Дата публикации не подтверждена.'], ['mystery_error', 'Не относится к информационной безопасности.'], [null, 'Не относится к информационной безопасности.'], ['http_status_503', null], ['publication_date_conflict', null]] as $index => [$error, $reason]) {
    $connection->insert('materials', ['id' => 'm'.$index, 'run_id' => $runId, 'input_id' => 'A'.$index, 'url' => 'https://example.org/'.$index, 'journal_status' => 'excluded', 'journal_reason' => $reason, 'journal_error' => $error]);
}
$connection->insert('model_calls', ['id' => 'c1', 'run_id' => $runId, 'stage' => 'analysis', 'duration_ms' => 999, 'input_tokens' => 999, 'output_tokens' => 999]);
$audits = [
    $collectionId => ['id' => $collectionId, 'settings' => ['collection' => ['discovery_sources' => [['url' => 'https://example.org/feed', 'kind' => 'rss'], ['url' => 'https://planned.example/news', 'kind' => 'html']]]]],
    $digestId => ['id' => $digestId, 'analysis' => ['generation' => [...$generation, 'response_id' => 'analysis-1', 'duration_ms' => 20], 'result' => ['evidence_checks' => [['match' => 'exact'], ['match' => 'typographic'], ['match' => 'unmatched', 'reason' => 'quote_not_found'], ['match' => 'exact', 'reason' => 'invalid_source_reference'], ['match' => 'typographic', 'reason' => 'unknown_reason']]]], 'export' => ['generation' => ['model' => 'test-export', 'response_id' => 'export-1', 'duration_ms' => 30], 'evidence_checks' => [], 'validated_analysis' => ['evidence_checks' => [['match' => 'exact']]]]],
];
$requests = 0;
$client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, $audits): MockResponse {
    ++$requests;
    assert('GET' === $method && str_starts_with($url, 'http://digest/v1/runs/'));
    assert(0 === $options['max_redirects'] && 3.0 === (float) $options['timeout'] && 5.0 === (float) $options['max_duration']);

    return new MockResponse(json_encode($audits[basename($url)], JSON_THROW_ON_ERROR));
});
$reader = new RunStatisticsAuditReader($client, 'http://digest', 'test-token');
$statistics = new RunStatistics($connection, new DigestRepository($connection, new RunDiagnostics($translator)), $reader, $translator);
$result = $statistics->forRun($runId);
assert(null !== $result && 'sites' === $result['inputMode']);
assert(1250.0 === $result['queueDurationMs']);
assert(7 === $result['counts']['excluded']);
assert(7 === array_sum(array_column($result['exclusions'], 'count')));
assert(['date_unconfirmed', 'download_error', 'limit', 'off_topic', 'other', 'outside_window'] === array_column($result['exclusions'], 'code'));
assert(3 === count($result['calls']));
assert(0.0 === $result['calls'][0]['durationMs'] && 0.0 === $result['calls'][0]['inputTokens']);
assert(20.0 === $result['calls'][1]['durationMs']);
assert(null === $result['calls'][2]['inputTokens']);
assert(['modelAttempts' => 1, 'webSearchCalls' => 2, 'traceAvailable' => true] === $result['search']);
assert(['stage' => 'analysis', 'available' => true, 'exact' => 1, 'normalized' => 1, 'notFound' => 1, 'other' => 2] === $result['evidence'][0]);
assert(0 === $result['evidence'][1]['exact'] && true === $result['evidence'][1]['available']);
$sources = array_column($result['discoverySources'], null, 'url');
assert(3 === count($sources));
assert('feed' === $sources['https://example.org/feed']['kind'] && 0 === $sources['https://example.org/feed']['uninspectedLinks']);
assert(false === $sources['https://planned.example/news']['attempted'] && null === $sources['https://planned.example/news']['found']);
assert(null === $sources['https://other.example/news']['inspectedLinks']);
assert(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'PRIVATE_'));
assert(null === $reader->read('../untrusted') && 2 === $requests);

// File runs have no search; an inaccessible audit falls back to stored DB metrics.
$connection->update('runs', ['settings_snapshot' => json_encode(['dataset' => 'fixture', 'go_digest_run_id' => $digestId], JSON_THROW_ON_ERROR)], ['id' => $runId]);
$unavailable = new RunStatisticsAuditReader(new MockHttpClient(new MockResponse('PRIVATE_ERROR', ['http_code' => 503])), 'http://digest', 'test-token');
$statistics = new RunStatistics($connection, new DigestRepository($connection, new RunDiagnostics($translator)), $unavailable, $translator);
$file = $statistics->forRun($runId, 'en');
assert(null !== $file && 'file' === $file['inputMode']);
assert(['modelAttempts' => 0, 'webSearchCalls' => null, 'traceAvailable' => false] === $file['search']);
assert(999.0 === $file['calls'][0]['durationMs']);
assert(false === $file['evidence'][0]['available']);
assert(str_contains($file['warnings'][0], 'unavailable'));
assert(null === $statistics->forRun('absent'));
foreach ([new MockResponse('{broken'), new MockResponse(json_encode(['id' => str_repeat('c', 32)], JSON_THROW_ON_ERROR)), new MockResponse(str_repeat('x', 16 * 1024 * 1024 + 1))] as $response) {
    assert(null === (new RunStatisticsAuditReader(new MockHttpClient($response), 'http://digest', 'test-token'))->read($digestId));
}

$stack = new RequestStack();
$stack->push(Request::create('/api/graphql'));
$authorization = new class implements AuthorizationCheckerInterface {
    public bool $allowed = false;

    public function isGranted(mixed $attribute, mixed $subject = null): bool
    {
        assert('ROLE_ADMIN' === $attribute);

        return $this->allowed;
    }
};
$query = new RunStatisticsQuery($statistics, new RequireAdmin($authorization, $translator, $stack), $stack);
try {
    $query->runStatistics($runId);
    throw new RuntimeException('Anonymous statistics access was allowed.');
} catch (UserError) {
}
$authorization->allowed = true;
assert(null === $query->runStatistics('../invalid'));
assert(null !== $query->runStatistics($runId));
echo "Run statistics smoke passed: authorization, audit limits, deduplication, missing metrics, source/evidence aggregates and database fallback.\n";
