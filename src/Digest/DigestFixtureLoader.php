<?php

declare(strict_types=1);

namespace App\Digest;

use App\Dataset\DatasetFileReader;
use App\Dataset\DatasetMaterial;
use App\Import\ImportRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Imports a saved model result and its original files without generation or delivery. */
final readonly class DigestFixtureLoader
{
    private const FILES = ['manifest.json', 'news.json', 'analysis.json', 'result.json', 'files/digest.md', 'files/post.md', 'files/email.md', 'files/journal.csv'];

    public function __construct(
        private Connection $connection,
        private DatasetFileReader $reader,
        private ImportRepository $imports,
        private DigestRepository $digests,
        private ValidatorInterface $validator,
    ) {
    }

    /** @return array{runId: string, alreadyLoaded: bool, eventCount: int} */
    public function load(string $directory): array
    {
        $directory = realpath($directory);
        if (false === $directory || !is_dir($directory)) {
            throw new \InvalidArgumentException('Fixture directory is not readable.');
        }
        $files = [];
        $hash = hash_init('sha256');
        foreach (self::FILES as $name) {
            $path = $directory.'/'.$name;
            if (!is_file($path) || !is_readable($path) || false === ($contents = file_get_contents($path))) {
                throw new \InvalidArgumentException('Cannot read fixture file: '.$name);
            }
            $files[$name] = $contents;
            hash_update($hash, $name."\0".strlen($contents)."\0".$contents);
        }
        $checksum = hash_final($hash);
        $manifest = $this->object($files['manifest.json']);
        $key = $this->requiredString($manifest, 'key');
        $sourceRunId = $this->requiredString($manifest, 'source_run_id');
        $subject = $this->requiredString($manifest, 'email_subject');
        $createdAt = new \DateTimeImmutable($this->requiredString($manifest, 'created_at'));
        $completedAt = new \DateTimeImmutable($this->requiredString($manifest, 'completed_at'));
        if ($completedAt < $createdAt) {
            throw new \InvalidArgumentException('Fixture completion precedes its creation.');
        }
        $result = GoDigestResult::fromArray($this->object($files['result.json']));
        if (0 !== count($this->validator->validate($result)) || count($result->recommendedOrder) !== count(array_unique($result->recommendedOrder))) {
            throw new \InvalidArgumentException('Invalid fixture result structure.');
        }
        $warnings = array_values(array_unique([...GoDigestResult::strings($manifest, 'warnings'), ...$result->warnings]));
        $dataset = $this->reader->read($directory.'/news.json');
        $controlTime = $dataset->controlTime ?? throw new \InvalidArgumentException('Fixture control time is missing.');
        $materials = [];
        foreach ($dataset->rows as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException('Invalid fixture material.');
            }
            $material = DatasetMaterial::fromRow($row);
            if (0 !== count($this->validator->validate($material)) || !is_string($material->inputId) || isset($materials[$material->inputId])) {
                throw new \InvalidArgumentException('Invalid or duplicate fixture material.');
            }
            $materials[$material->inputId] = $material;
        }
        $journal = $this->journal($files['files/journal.csv']);
        if (count($journal) !== count($materials) || [] !== array_diff_key($journal, $materials)) {
            throw new \InvalidArgumentException('Fixture journal must contain one row for every material.');
        }

        return $this->connection->transactional(function () use ($directory, $files, $checksum, $manifest, $key, $sourceRunId, $subject, $createdAt, $completedAt, $result, $warnings, $dataset, $controlTime, $materials, $journal): array {
            $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))', ['key' => $key])->fetchOne();
            $existing = $this->connection->fetchAssociative(<<<'SQL'
                SELECT id, settings_snapshot->>'fixture_checksum' AS checksum,
                    (SELECT COUNT(*) FROM events WHERE run_id = runs.id) AS event_count
                FROM runs WHERE settings_snapshot->>'fixture_key' = :key
                SQL, ['key' => $key]);
            if (false !== $existing) {
                if ($checksum !== $existing['checksum']) {
                    throw new \InvalidArgumentException('Fixture key is already loaded with different content.');
                }

                return ['runId' => (string) $existing['id'], 'alreadyLoaded' => true, 'eventCount' => (int) $existing['event_count']];
            }

            $runId = $this->imports->createRun($controlTime, [
                'dataset' => $dataset->datasetName, 'period_start' => $dataset->periodStart?->format(DATE_ATOM),
                'fixture_key' => $key, 'fixture_checksum' => $checksum, 'source_run_id' => $sourceRunId,
                'source_created_at' => $manifest['created_at'], 'source_completed_at' => $manifest['completed_at'],
                'fixture_manifest' => $manifest, 'fixture_warnings' => $warnings,
            ]);
            $inputId = $this->imports->createInput($runId, 'dataset', 'news.json', $directory.'/news.json', hash('sha256', $files['news.json']), 'completed');
            foreach ($materials as $material) {
                $this->imports->saveMaterial($runId, $inputId, $material);
            }
            $materialIds = [];
            foreach ($this->digests->materials($runId) as $material) {
                $inputId = (string) $material['input_id'];
                $materialId = (string) $material['id'];
                $materialIds[$inputId] = $materialId;
                $decision = $journal[$inputId];
                $this->digests->decideMaterial($materialId, $decision['status'], $decision['group'], $decision['reason'], $decision['error']);
            }
            $this->saveEvents($runId, $result, $materialIds);
            $artifacts = [];
            foreach (['digest.md', 'post.md', 'email.md', 'journal.csv'] as $name) {
                $artifacts[$name] = $files['files/'.$name];
            }
            $this->digests->saveArtifacts($runId, $artifacts, $subject);
            if (!$this->digests->claim($runId)) {
                throw new \RuntimeException('Cannot complete the fixture run.');
            }
            $this->digests->complete($runId, $result, $warnings);
            // Fixture publication deliberately creates no subscriber snapshot or deliveries.
            $this->connection->executeStatement(<<<'SQL'
                UPDATE runs SET created_at = :created_at, started_at = :created_at,
                    finished_at = :completed_at, published_at = CURRENT_TIMESTAMP WHERE id = :id
                SQL, ['id' => $runId, 'created_at' => $createdAt->format('Y-m-d\TH:i:s.uP'), 'completed_at' => $completedAt->format('Y-m-d\TH:i:s.uP')]);

            return ['runId' => $runId, 'alreadyLoaded' => false, 'eventCount' => count($result->recommendedOrder)];
        });
    }

    /** @param array<string, string> $materialIds */
    private function saveEvents(string $runId, GoDigestResult $result, array $materialIds): void
    {
        $groups = [];
        foreach ($result->groups as $group) {
            $id = $this->requiredString($group, 'id');
            if (isset($groups[$id])) {
                throw new \InvalidArgumentException('Duplicate fixture group.');
            }
            $ids = GoDigestResult::strings($group, 'ids');
            if (count($ids) !== count(array_unique($ids)) || [] !== array_diff($ids, array_keys($materialIds))) {
                throw new \InvalidArgumentException('Invalid fixture group materials.');
            }
            $facts = [];
            foreach (GoDigestResult::records($group, 'facts') as $fact) {
                $evidence = [];
                foreach (GoDigestResult::records($fact, 'evidence') as $source) {
                    $articleId = GoDigestResult::string($source, 'articleId');
                    if (!in_array($articleId, $ids, true)) {
                        throw new \InvalidArgumentException('Fixture evidence references a material outside its group.');
                    }
                    $evidence[] = ['articleId' => $articleId, 'quote' => GoDigestResult::string($source, 'quote')];
                }
                $facts[] = ['statement' => $this->requiredString($fact, 'statement'), 'evidence' => $evidence];
            }
            $groups[$id] = ['id' => $id, 'ids' => $ids, 'facts' => $facts];
        }
        $events = [];
        foreach ($result->events as $event) {
            $id = $this->requiredString($event, 'groupId');
            if (isset($events[$id])) {
                throw new \InvalidArgumentException('Duplicate fixture event.');
            }
            $events[$id] = [
                'title' => $this->requiredString($event, 'title'), 'summary' => $this->requiredString($event, 'summary'),
                'consequences' => $this->requiredString($event, 'consequences'), 'reason' => $this->requiredString($event, 'reason'),
                'post' => GoDigestResult::string($event, 'post'),
            ];
        }
        foreach ($result->recommendedOrder as $index => $id) {
            if (!isset($groups[$id], $events[$id]) || [] === $groups[$id]['ids']) {
                throw new \InvalidArgumentException('Selected fixture group is missing its sources or event text.');
            }
            $this->digests->saveEvent($runId, $index + 1, $groups[$id], $events[$id], array_intersect_key($materialIds, array_fill_keys($groups[$id]['ids'], true)));
        }
    }

    /** @return array<string, array{status: string, group: ?string, reason: string, error: ?string}> */
    private function journal(string $contents): array
    {
        $stream = fopen('php://temp', 'w+');
        if (false === $stream) {
            throw new \RuntimeException('Cannot read fixture journal.');
        }
        try {
            fwrite($stream, str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents);
            rewind($stream);
            if (['id', 'status', 'group', 'reason', 'error'] !== fgetcsv($stream, 0, ';', '"', '')) {
                throw new \InvalidArgumentException('Invalid fixture journal header.');
            }
            $journal = [];
            while (false !== ($row = fgetcsv($stream, 0, ';', '"', ''))) {
                if (5 !== count($row) || !is_string($row[0]) || '' === $row[0] || isset($journal[$row[0]]) || !is_string($row[3]) || '' === trim($row[3])) {
                    throw new \InvalidArgumentException('Invalid or duplicate fixture journal row.');
                }
                $status = match ($row[1]) {
                    'включён', 'включен', 'included' => 'included',
                    'объединён', 'объединен', 'merged' => 'merged',
                    'исключён', 'исключен', 'excluded' => 'excluded',
                    default => throw new \InvalidArgumentException('Unknown fixture journal status.'),
                };
                $journal[$row[0]] = ['status' => $status, 'group' => '' === $row[2] ? null : $row[2], 'reason' => $row[3], 'error' => '' === $row[4] ? null : $row[4]];
            }

            return $journal;
        } finally {
            fclose($stream);
        }
    }

    /** @return array<string, mixed> */
    private function object(string $contents): array
    {
        $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data)) {
            throw new \InvalidArgumentException('Fixture JSON must be an object.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key): string
    {
        $value = GoDigestResult::string($data, $key);
        if ('' === trim($value)) {
            throw new \InvalidArgumentException('Empty fixture field: '.$key);
        }

        return $value;
    }
}
