<?php

declare(strict_types=1);

namespace App\Digest;

use Doctrine\DBAL\Connection;

final readonly class DigestRepository
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return array<string, mixed>|null */
    public function run(string $runId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT id, control_time, status, settings_snapshot FROM runs WHERE id = :id', ['id' => $runId]);

        return false === $row ? null : $row;
    }

    public function claim(string $runId): bool
    {
        return 1 === $this->connection->executeStatement("UPDATE runs SET status = 'running', started_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'queued'", ['id' => $runId]);
    }

    public function fail(string $runId, string $reason): void
    {
        $this->connection->executeStatement("UPDATE runs SET status = 'failed', error = :error, finished_at = CURRENT_TIMESTAMP WHERE id = :id AND status = 'running'", ['id' => $runId, 'error' => $reason]);
    }

    /** @return list<array<string, mixed>> */
    public function materials(string $runId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT id, input_id, title, body, source_name, url, published_at, input_missing, source_error,
                   journal_status, journal_group, journal_reason, journal_error
            FROM materials WHERE run_id = :run_id ORDER BY input_id, id
            SQL, ['run_id' => $runId]);
    }

    /** @return list<array<string, mixed>> */
    public function importErrors(string $runId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT die.row_number, die.input_id, die.error
            FROM dataset_import_errors die
            JOIN run_inputs ri ON ri.id = die.run_input_id
            WHERE ri.run_id = :run_id ORDER BY die.row_number, die.id
            SQL, ['run_id' => $runId]);
    }

    /** @return list<array<string, mixed>> */
    public function events(string $runId): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, rank, group_key, title, summary, consequences, selection_reason, post_text FROM events WHERE run_id = :run_id ORDER BY rank', ['run_id' => $runId]);
    }

    /** @return list<array<string, mixed>> */
    public function eventSources(string $eventId): array
    {
        return $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT m.input_id, m.source_name, m.url, m.published_at
            FROM event_materials em JOIN materials m ON m.id = em.material_id
            WHERE em.event_id = :event_id ORDER BY em.is_primary_source DESC, m.input_id
            SQL, ['event_id' => $eventId]);
    }

    /** @param array<string, mixed> $group
     * @param array<string, mixed>  $event
     * @param array<string, string> $materialIds
     */
    public function saveEvent(string $runId, int $rank, array $group, array $event, array $materialIds): void
    {
        $eventId = (string) $this->connection->fetchOne(<<<'SQL'
            INSERT INTO events (id, run_id, rank, group_key, title, summary, consequences, selection_reason, post_text)
            VALUES (gen_random_uuid(), :run_id, :rank, :group_key, :title, :summary, :consequences, :reason, :post)
            RETURNING id
            SQL, [
            'run_id' => $runId, 'rank' => $rank, 'group_key' => $group['id'], 'title' => $event['title'],
            'summary' => $event['summary'], 'consequences' => $event['consequences'],
            'reason' => $event['reason'], 'post' => $event['post'],
        ]);

        foreach ($group['ids'] as $index => $inputId) {
            $materialId = $materialIds[$inputId] ?? null;
            if (null === $materialId) {
                continue;
            }
            $this->connection->insert('event_materials', [
                'event_id' => $eventId, 'material_id' => $materialId, 'run_id' => $runId,
                'is_primary_source' => 0 === $index,
            ]);
        }

        foreach ($group['facts'] as $fact) {
            foreach ($fact['evidence'] as $evidence) {
                $materialId = $materialIds[$evidence['articleId']] ?? null;
                if (null === $materialId || !is_string($evidence['quote']) || '' === trim($evidence['quote'])) {
                    continue;
                }
                $this->connection->insert('event_evidence', [
                    'id' => $this->connection->fetchOne('SELECT gen_random_uuid()'),
                    'event_id' => $eventId, 'material_id' => $materialId,
                    'claim' => $fact['statement'], 'source_excerpt' => $evidence['quote'],
                    'qualification' => null,
                ]);
            }
        }
    }

    public function decideMaterial(string $materialId, string $status, ?string $group, string $reason, ?string $error): void
    {
        $this->connection->update('materials', [
            'journal_status' => $status, 'journal_group' => $group,
            'journal_reason' => $reason, 'journal_error' => $error,
        ], ['id' => $materialId]);
    }

    /** @param list<string> $warnings */
    public function complete(string $runId, GoDigestResult $result, array $warnings): void
    {
        $run = $this->run($runId) ?? throw new \InvalidArgumentException('Unknown run.');
        $settings = json_decode((string) $run['settings_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($settings)) {
            throw new \UnexpectedValueException('Invalid run settings.');
        }
        $snapshot = [
            'selection_summary' => $result->selectionSummary,
            'digest_warnings' => $warnings,
            'analysis_usage' => $result->analysisUsageJson,
            'draft_usage' => $result->draftUsageJson,
        ];
        $updated = $this->connection->executeStatement(<<<'SQL'
            UPDATE runs SET status = 'completed', finished_at = CURRENT_TIMESTAMP,
                model_version = :model_version, prompt_version = :prompt_version,
                settings_snapshot = :snapshot
            WHERE id = :id AND status = 'running'
            SQL, [
            'id' => $runId,
            'model_version' => $result->analysisModel.' / '.$result->draftModel,
            'prompt_version' => $result->analysisPromptVersion.' / '.$result->draftPromptVersion,
            'snapshot' => json_encode([...$settings, ...$snapshot], JSON_THROW_ON_ERROR),
        ]);
        if (1 !== $updated) {
            throw new \UnexpectedValueException('Run was not completed.');
        }
        foreach (['analysis' => [$result->analysisDurationMs, $result->analysisUsageJson], 'draft' => [$result->draftDurationMs, $result->draftUsageJson]] as $stage => [$duration, $usageJson]) {
            $usage = null === $usageJson ? [] : json_decode($usageJson, true, 512, JSON_THROW_ON_ERROR);
            $this->connection->executeStatement('INSERT INTO model_calls (id, run_id, stage, input_tokens, output_tokens, duration_ms) VALUES (gen_random_uuid(), :run_id, :stage, :input_tokens, :output_tokens, :duration)', [
                'run_id' => $runId, 'stage' => $stage, 'duration' => $duration,
                'input_tokens' => is_array($usage) && is_int($usage['input_tokens'] ?? null) ? $usage['input_tokens'] : null,
                'output_tokens' => is_array($usage) && is_int($usage['output_tokens'] ?? null) ? $usage['output_tokens'] : null,
            ]);
        }
    }

    /** @param array<string, string> $artifacts */
    public function saveArtifacts(string $runId, array $artifacts, string $subject): void
    {
        foreach (['digest' => 'digest.md', 'post' => 'post.md', 'email_preview' => 'email.md', 'journal' => 'journal.csv'] as $kind => $filename) {
            $id = (string) $this->connection->fetchOne(<<<'SQL'
                INSERT INTO artifacts (id, run_id, kind, content) VALUES (gen_random_uuid(), :run_id, :kind, :content)
                RETURNING id
                SQL, ['run_id' => $runId, 'kind' => $kind, 'content' => $artifacts[$filename]]);
            if ('email_preview' === $kind) {
                $this->connection->insert('email_preview_details', ['artifact_id' => $id, 'subject' => $subject]);
            }
        }
    }
}
