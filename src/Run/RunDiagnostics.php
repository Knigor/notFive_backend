<?php

declare(strict_types=1);

namespace App\Run;

use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class RunDiagnostics
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /** @param array<string, mixed> $settings
     * @param list<array<string, mixed>> $materials
     * @param list<array<string, mixed>> $importErrors
     *
     * @return array<string, mixed>
     */
    public function build(array $settings, array $materials, array $importErrors, int $eventCount, string $locale = 'ru', bool $finished = false): array
    {
        $collection = is_array($settings['go_collection'] ?? null) ? $settings['go_collection'] : [];
        $research = self::decode($collection['researchJson'] ?? null);
        $rows = [];
        $ids = [];
        $urls = [];
        foreach ($materials as $material) {
            $this->append($rows, $ids, $urls, [
                'id' => (string) $material['input_id'], 'url' => $material['url'], 'title' => $material['title'],
                'stage' => null === $material['journal_status'] ? 'import' : 'analysis',
                'status' => $material['journal_status'] ?? ($finished ? 'excluded' : 'pending'), 'group' => $material['journal_group'],
                'reason' => $material['journal_reason'] ?? ($finished ? $this->translator->trans('run_diagnostics.missing_analysis_decision', locale: $locale) : null), 'error' => $material['journal_error'] ?? $material['source_error'],
            ], false);
        }
        foreach ($importErrors as $error) {
            $this->append($rows, $ids, $urls, [
                'id' => $error['input_id'] ?? 'import-'.($error['id'] ?? $error['row_number']),
                'url' => null, 'title' => null, 'stage' => 'import', 'status' => 'excluded', 'group' => null,
                'reason' => $this->translator->trans('digest_export.import_error', locale: $locale), 'error' => $error['error'],
            ], false);
        }
        foreach (self::records($settings['collection_import_errors'] ?? null) as $error) {
            $this->candidate($rows, $ids, $urls, $error, 'import', 'excluded', $locale);
        }
        foreach (self::records($collection['articles'] ?? null) as $article) {
            $imported = true === ($settings['collection_import_complete'] ?? false) || $finished;
            if ($imported) {
                $article['reason'] = 'collection_material_missing';
                $article['error'] = 'collection_material_missing';
            }
            $this->candidate($rows, $ids, $urls, $article, 'import', $imported ? 'excluded' : 'pending', $locale);
        }
        foreach (self::records($research['sources'] ?? null) as $source) {
            if (isset($ids[(string) ($source['id'] ?? '')]) && is_string($source['requested_url'] ?? null)) {
                $urls[self::urlKey($source['requested_url'])] = true;
            }
        }
        foreach (self::records($research['candidate_audit'] ?? null) as $candidate) {
            $accepted = 'accepted' === ($candidate['status'] ?? null);
            if ($accepted && $finished) {
                $candidate['reason'] = 'collection_material_missing';
                $candidate['error'] = 'collection_material_missing';
            }
            $status = $accepted && !$finished ? 'pending' : 'excluded';
            $this->candidate($rows, $ids, $urls, $candidate, $accepted ? 'import' : 'collection', $status, $locale);
        }
        foreach ([self::records($research['excluded_sources'] ?? null), self::records($collection['excluded'] ?? null), self::records($research['rejected_candidates'] ?? null)] as $candidates) {
            foreach ($candidates as $candidate) {
                $this->candidate($rows, $ids, $urls, $candidate, 'collection', 'excluded', $locale);
            }
        }
        foreach (self::records($research['discovery'] ?? null) as $discovery) {
            foreach (self::records($discovery['rejected'] ?? null) as $candidate) {
                $this->candidate($rows, $ids, $urls, $candidate, 'collection', 'excluded', $locale);
            }
        }
        foreach (self::strings($research['citation_urls'] ?? null) as $url) {
            $this->candidate($rows, $ids, $urls, ['url' => $url, 'reason' => 'not_processed', 'error' => 'not_processed'], 'collection', 'excluded', $locale);
        }
        $warnings = array_values(array_unique([
            ...self::strings($collection['warnings'] ?? null),
            ...self::strings($research['warnings'] ?? null),
            ...self::strings($settings['digest_warnings'] ?? null),
        ]));
        $counts = [
            'candidates' => count($rows), 'collected' => count(self::records($collection['articles'] ?? null)),
            'imported' => count($materials), 'included' => 0, 'merged' => 0, 'excluded' => 0, 'events' => $eventCount,
        ];
        foreach ($rows as $row) {
            if (in_array($row['status'], ['included', 'merged', 'excluded'], true)) {
                ++$counts[$row['status']];
            }
        }

        return [
            'collectionRunId' => self::string($collection['runId'] ?? null),
            'digestRunId' => self::string($settings['go_digest_run_id'] ?? null),
            'counts' => $counts, 'warnings' => $warnings, 'materials' => $rows,
        ];
    }

    /** @param list<array<string, mixed>> $rows
     * @param array<string, true>  $ids
     * @param array<string, true>  $urls
     * @param array<string, mixed> $candidate
     */
    private function candidate(array &$rows, array &$ids, array &$urls, array $candidate, string $stage, string $status, string $locale): void
    {
        $url = self::string($candidate['url'] ?? null);
        $id = self::string($candidate['id'] ?? null);
        if (null === $id && null === $url) {
            return;
        }
        $reason = self::string($candidate['reason'] ?? null);
        if (null === $reason && 'excluded' === $status) {
            $reason = 'collection_excluded';
        }
        if (null !== $reason) {
            $key = 'run_diagnostics.'.$reason;
            $translated = $this->translator->trans($key, locale: $locale);
            $reason = $translated === $key ? $reason : $translated;
        }
        $this->append($rows, $ids, $urls, [
            'id' => $id ?? 'web-'.substr(hash('sha256', $url), 0, 16),
            'url' => $url, 'title' => self::string($candidate['title'] ?? null),
            'stage' => $stage, 'status' => $status, 'group' => null, 'reason' => $reason,
            'error' => self::string($candidate['error'] ?? null),
        ]);
    }

    /** @param list<array<string, mixed>> $rows
     * @param array<string, true>  $ids
     * @param array<string, true>  $urls
     * @param array<string, mixed> $row
     */
    private function append(array &$rows, array &$ids, array &$urls, array $row, bool $deduplicateUrl = true): void
    {
        $id = (string) $row['id'];
        $url = is_string($row['url']) ? self::urlKey($row['url']) : null;
        if (isset($ids[$id]) || ($deduplicateUrl && null !== $url && isset($urls[$url]))) {
            return;
        }
        $ids[$id] = true;
        if (null !== $url) {
            $urls[$url] = true;
        }
        $rows[] = $row;
    }

    /** @return array<string, mixed> */
    public static function decode(mixed $json): array
    {
        if (!is_string($json)) {
            return [];
        }
        $value = json_decode($json, true);

        return is_array($value) ? $value : [];
    }

    /** @return list<array<string, mixed>> */
    private static function records(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }

    private static function urlKey(string $url): string
    {
        $parts = parse_url($url);
        if (false === $parts || !isset($parts['host'])) {
            return $url;
        }

        return strtolower($parts['scheme'] ?? 'https').'://'.strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
