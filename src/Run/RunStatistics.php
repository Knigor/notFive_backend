<?php

declare(strict_types=1);

namespace App\Run;

use App\Digest\DigestRepository;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class RunStatistics
{
    public function __construct(private Connection $connection, private DigestRepository $repository, private RunStatisticsAuditReader $audits, private TranslatorInterface $translator)
    {
    }

    /** @return array<string, mixed>|null */
    public function forRun(string $runId, string $locale = 'ru'): ?array
    {
        $run = $this->connection->fetchAssociative('SELECT id, status, created_at, started_at, settings_snapshot FROM runs WHERE id = :id', ['id' => $runId]);
        if (false === $run) {
            return null;
        }
        $settings = RunDiagnostics::decode($run['settings_snapshot']);
        $collection = self::object($settings['go_collection'] ?? null);
        $collectionAudit = $this->audits->read($collection['runId'] ?? null);
        $digestAudit = ($settings['go_digest_run_id'] ?? null) === ($collection['runId'] ?? null) ? $collectionAudit : $this->audits->read($settings['go_digest_run_id'] ?? null);
        $research = RunDiagnostics::decode($collection['researchJson'] ?? null);
        if ([] === $research) {
            $research = self::object($collectionAudit['research'] ?? null);
        }
        $diagnostics = $this->repository->runDiagnostics($runId, $locale);
        $calls = $this->generations($research, $digestAudit ?? []);
        $auditedStages = array_column($calls, 'stage');
        $fallbackAttempts = [];
        foreach ($this->connection->fetchAllAssociative('SELECT stage, duration_ms, input_tokens, output_tokens FROM model_calls WHERE run_id = :id ORDER BY stage, id', ['id' => $runId]) as $fallback) {
            $stage = 'draft' === $fallback['stage'] ? 'export' : $fallback['stage'];
            if (!in_array($stage, ['search', 'analysis', 'export'], true) || in_array($stage, $auditedStages, true)) {
                continue;
            }
            $fallbackAttempts[$stage] = ($fallbackAttempts[$stage] ?? 0) + 1;
            $calls[] = ['stage' => $stage, 'attempt' => $fallbackAttempts[$stage], 'model' => null,
                'durationMs' => self::number($fallback['duration_ms']), 'inputTokens' => self::number($fallback['input_tokens']), 'outputTokens' => self::number($fallback['output_tokens'])];
        }
        $evidence = $this->evidence($digestAudit ?? []);
        $warnings = [];
        if ((isset($settings['go_digest_run_id']) && null === $digestAudit) || (isset($collection['runId']) && null === $collectionAudit && [] === $research)) {
            $warnings[] = $this->translator->trans('run_statistics.audit_unavailable', locale: $locale);
        }
        $partial = in_array(false, array_column($evidence, 'available'), true);
        foreach ($calls as $call) {
            $partial = $partial || null === $call['durationMs'] || null === $call['inputTokens'] || null === $call['outputTokens'];
        }
        if ($partial) {
            $warnings[] = $this->translator->trans('run_statistics.partial_metrics', locale: $locale);
        }

        return [
            'runId' => (string) $run['id'], 'status' => (string) $run['status'], 'inputMode' => $this->inputMode($settings),
            'queueDurationMs' => $this->queueDuration($run), 'counts' => $diagnostics['counts'],
            'exclusions' => $this->exclusions(self::records($diagnostics['materials'])), 'calls' => $calls,
            'discoverySources' => $this->discovery(self::object($collectionAudit['settings'] ?? null), $research, $locale),
            'evidence' => $evidence, 'search' => $this->search($research, $calls), 'warnings' => $warnings,
        ];
    }

    /** @param array<string, mixed> $settings */
    private function inputMode(array $settings): string
    {
        if (array_key_exists('dataset', $settings)) {
            return 'file';
        }
        if (is_array($settings['source_urls'] ?? null)) {
            return [] === $settings['source_urls'] ? 'default' : 'sites';
        }

        return 'unknown';
    }

    /** @param array<string, mixed> $run */
    private function queueDuration(array $run): ?float
    {
        if (!is_string($run['started_at']) || !is_string($run['created_at'])) {
            return null;
        }
        try {
            $duration = (float) (new \DateTimeImmutable($run['started_at']))->format('U.u') - (float) (new \DateTimeImmutable($run['created_at']))->format('U.u');

            return $duration < 0 ? null : $duration * 1000;
        } catch (\Exception) {
            return null;
        }
    }

    /** @param list<array<string, mixed>> $materials
     * @return list<array<string, mixed>>
     */
    private function exclusions(array $materials): array
    {
        $groups = [];
        foreach ($materials as $row) {
            if ('excluded' !== ($row['status'] ?? null)) {
                continue;
            }
            $code = $this->exclusionCode($row);
            $stage = in_array($row['stage'] ?? null, ['collection', 'import', 'analysis'], true) ? $row['stage'] : 'unknown';
            $site = self::site($row['url'] ?? null);
            $key = $code.'|'.$stage.'|'.$site;
            $groups[$key] ??= ['code' => $code, 'stage' => $stage, 'site' => $site, 'count' => 0];
            ++$groups[$key]['count'];
        }
        ksort($groups);

        return array_values($groups);
    }

    /** @param array<string, mixed> $row */
    private function exclusionCode(array $row): string
    {
        $error = $row['error'] ?? null;
        foreach ([
            'outside_window' => ['outside_window', 'outside_period', 'after_control_time', 'feed_outside_period'],
            'date_unconfirmed' => ['date_unconfirmed', 'date_missing', 'missing_date', 'invalid_publication_date', 'publication_date_missing', 'publication_date_unconfirmed', 'publication_date_conflict', 'publication_metadata_limit', 'publication_date_format_or_timezone', 'publication_date_missing_timezone'],
            'download_error' => ['fetch_failed', 'download_failed', 'source_unavailable', 'network_error', 'http_error', 'timeout', 'request_timeout', 'request_cancelled', 'transport_error', 'body_read_error', 'redirect_limit'],
            'off_topic' => ['off_topic', 'not_relevant', 'irrelevant'],
            'limit' => ['fetch_limit', 'candidate_limit', 'event_limit', 'limit_reached', 'discovery_limit'],
        ] as $code => $errors) {
            if (in_array($error, $errors, true)) {
                return $code;
            }
        }
        if (is_string($error) && 1 === preg_match('/^http_status_[1-5][0-9]{2}$/D', $error)) {
            return 'download_error';
        }
        // An explicit but unknown error is not reclassified from arbitrary model prose.
        if (is_string($error) && '' !== $error) {
            return 'other';
        }
        $reason = is_string($row['reason'] ?? null) ? $row['reason'] : '';
        foreach ([
            'outside_window' => '/за пределами (?:периода|окна)|вне (?:заданного |выбранного )?(?:периода|окна)|outside (?:the )?(?:time |publication )?window/iu',
            'date_unconfirmed' => '/дата (?:публикации )?не (?:подтверждена|указана)|нет даты публикации|publication date (?:is )?(?:missing|unconfirmed)|cannot confirm (?:the )?publication date/iu',
            'off_topic' => '/не (?:относится|связан[ао]?) (?:к |с )?(?:информационной безопасност|кибербезопасност)|not (?:related|relevant) to (?:cybersecurity|information security)/iu',
            'limit' => '/достигнут (?:общий )?лимит|(?:fetch|candidate|event) limit (?:was )?reached/iu',
        ] as $code => $pattern) {
            if (1 === preg_match($pattern, $reason)) {
                return $code;
            }
        }

        return 'other';
    }

    /** @param array<string, mixed> $research
     * @param array<string, mixed> $audit
     *
     * @return list<array<string, mixed>>
     */
    private function generations(array $research, array $audit): array
    {
        $calls = [];
        $search = self::records($research['search_attempts'] ?? null);
        $generations = [] === $search ? [self::object($research['generation'] ?? null)] : array_map(static fn (array $attempt): array => self::object($attempt['generation'] ?? null), $search);
        foreach (['search' => $generations, 'analysis' => [self::object($audit['analysis']['generation'] ?? null)], 'export' => [self::object($audit['export']['generation'] ?? null)]] as $stage => $records) {
            $seen = [];
            foreach ($records as $index => $generation) {
                $started = $generation['started_at'] ?? null;
                if (empty($generation['model']) && empty($generation['response_id']) && (!is_string($started) || str_starts_with($started, '0001-')) && empty($generation['duration_ms']) && empty($generation['usage']) && empty($generation['trace'])) {
                    continue;
                }
                $responseId = !empty($generation['response_id']) ? $generation['response_id'] : ($generation['trace']['id'] ?? null);
                if (is_string($responseId) && '' !== $responseId) {
                    if (isset($seen[$responseId])) {
                        continue;
                    }
                    $seen[$responseId] = true;
                }
                $usage = self::object($generation['usage'] ?? null);
                $model = $generation['model'] ?? null;
                $calls[] = [
                    'stage' => $stage, 'attempt' => $index + 1,
                    'model' => is_string($model) && 1 === preg_match('~^[a-zA-Z0-9_./:@-]{1,256}$~D', $model) ? $model : null,
                    'durationMs' => self::number($generation['duration_ms'] ?? null),
                    'inputTokens' => self::number($usage['input_tokens'] ?? null), 'outputTokens' => self::number($usage['output_tokens'] ?? null),
                ];
            }
        }

        return $calls;
    }

    /** @param array<string, mixed> $settings
     * @param array<string, mixed> $research
     *
     * @return list<array<string, mixed>>
     */
    private function discovery(array $settings, array $research, string $locale): array
    {
        $sources = [];
        $planned = $settings['discovery_sources'] ?? $settings['collection']['discovery_sources'] ?? null;
        foreach ([false => self::records($planned), true => self::records($research['discovery'] ?? null)] as $attempted => $records) {
            foreach ($records as $record) {
                $url = $record['url'] ?? null;
                if (!is_string($url) || 'unknown' === self::site($url) || null !== parse_url($url, PHP_URL_USER) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                    continue;
                }
                $sources[$url] = [
                    'url' => $url, 'site' => self::site($url),
                    'kind' => match ($record['kind'] ?? null) {
                        'rss', 'atom', 'feed' => 'feed', 'html' => 'html', default => 'unknown',
                    },
                    'attempted' => (bool) $attempted,
                    'found' => $attempted ? self::integer($record['found'] ?? null) : null,
                    'inspectedLinks' => $attempted ? self::integer($record['inspected_links'] ?? null) : null,
                    'uninspectedLinks' => $attempted ? self::integer($record['uninspected_links'] ?? null) : null,
                    'outsideWindow' => $attempted ? self::integer($record['outside_window'] ?? null) : null,
                    'error' => !empty($record['error_code']) || !empty($record['error']) ? $this->translator->trans('run_statistics.discovery_error', locale: $locale) : null,
                ];
            }
        }

        return array_values($sources);
    }

    /** @param array<string, mixed> $audit
     * @return list<array<string, mixed>>
     */
    private function evidence(array $audit): array
    {
        $checks = [
            'analysis' => $audit['analysis']['result']['evidence_checks'] ?? null,
            'export' => $audit['export']['evidence_checks'] ?? $audit['export']['validated_analysis']['evidence_checks'] ?? null,
        ];
        $result = [];
        foreach ($checks as $stage => $records) {
            $row = ['stage' => $stage, 'available' => is_array($records), 'exact' => 0, 'normalized' => 0, 'notFound' => 0, 'other' => 0];
            foreach (self::records($records) as $check) {
                $reason = $check['reason'] ?? null;
                $key = null !== $reason && '' !== $reason ? ('quote_not_found' === $reason ? 'notFound' : 'other') : match ($check['match'] ?? null) {
                    'exact' => 'exact', 'typographic' => 'normalized', 'unmatched' => 'notFound', default => 'other',
                };
                ++$row[$key];
            }
            $result[] = $row;
        }

        return $result;
    }

    /** @param array<string, mixed> $research
     * @param list<array<string, mixed>> $calls
     *
     * @return array<string, mixed>
     */
    private function search(array $research, array $calls): array
    {
        // New audits aggregate all attempts here. Do not add the same trace calls again.
        $aggregated = [];
        foreach (self::records($research['search_calls'] ?? null) as $index => $item) {
            if ('web_search_call' === ($item['type'] ?? null)) {
                $aggregated[is_string($item['id'] ?? null) ? $item['id'] : (string) $index] = true;
            }
        }
        $attemptCount = count(array_filter($calls, static fn (array $call): bool => 'search' === $call['stage']));
        if ([] !== $aggregated) {
            return ['modelAttempts' => $attemptCount, 'webSearchCalls' => count($aggregated), 'traceAvailable' => true];
        }
        $attempts = self::records($research['search_attempts'] ?? null);
        $generations = [] === $attempts ? [self::object($research['generation'] ?? null)] : array_map(static fn (array $attempt): array => self::object($attempt['generation'] ?? null), $attempts);
        $observed = false;
        $tools = [];
        foreach ($generations as $index => $generation) {
            $output = $generation['trace']['output'] ?? null;
            if (!is_array($output)) {
                continue;
            }
            $observed = true;
            foreach (self::records($output) as $position => $item) {
                if ('web_search_call' === ($item['type'] ?? null)) {
                    $id = is_string($item['id'] ?? null) ? $item['id'] : $index.':'.$position;
                    $tools[$id] = true;
                }
            }
        }
        $observed = $observed || true === ($research['search_observed'] ?? null);

        return ['modelAttempts' => $attemptCount, 'webSearchCalls' => $observed ? count($tools) : null, 'traceAvailable' => $observed];
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return list<array<string, mixed>> */
    private static function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value >= 0 ? (float) $value : null;
    }

    private static function integer(mixed $value): ?int
    {
        $number = self::number($value);

        return null !== $number && $number <= 2147483647 && floor($number) === $number ? (int) $number : null;
    }

    private static function site(mixed $url): string
    {
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;

        return is_string($host) && 1 === preg_match('/^[a-z0-9.-]+$/iD', $host) ? strtolower($host) : 'unknown';
    }
}
