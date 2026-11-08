<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Dataset\DatasetMaterial;
use App\Digest\DigestRepository;
use App\Import\ImportRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;

final class DemoDigestFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private readonly ImportRepository $imports,
        private readonly DigestRepository $digests,
    ) {
    }

    public static function getGroups(): array
    {
        return ['demo-digest'];
    }

    public function load(ObjectManager $manager): void
    {
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('The demo digest fixture requires the Doctrine ORM executor.');
        }

        $path = dirname(__DIR__, 2).'/fixtures/digest/2026-11-08/data.php';
        $snapshot = require $path;
        $sourceRunId = $snapshot['source_run_id'];
        $connection = $manager->getConnection();

        // Use the original import lock and source ID to recognize previously seeded databases.
        $connection->fetchOne(
            'SELECT pg_advisory_xact_lock(hashtextextended(:key, 0))',
            ['key' => 'go-pipeline-'.$sourceRunId],
        );
        if (false !== $connection->fetchOne(
            "SELECT id FROM runs WHERE settings_snapshot->>'source_run_id' = :id",
            ['id' => $sourceRunId],
        )) {
            return;
        }

        $runId = $this->imports->createRun(new \DateTimeImmutable($snapshot['control_time']), [
            'source_run_id' => $sourceRunId,
            'period_start' => $snapshot['period_start'],
            'selection_summary' => $snapshot['selection_summary'],
            'digest_warnings' => $snapshot['warnings'],
        ]);
        $inputId = $this->imports->createInput(
            $runId,
            'dataset',
            'data.php',
            $path,
            hash_file('sha256', $path) ?: throw new \RuntimeException('Cannot hash demo digest data.'),
            'completed',
        );
        $decisions = [];
        foreach ($snapshot['materials'] as $row) {
            $this->imports->saveMaterial($runId, $inputId, DatasetMaterial::fromRow($row));
            $decisions[$row['id']] = $row['decision'];
        }

        $materialIds = [];
        foreach ($this->digests->materials($runId) as $material) {
            $id = (string) $material['id'];
            $inputKey = (string) $material['input_id'];
            $materialIds[$inputKey] = $id;
            $decision = $decisions[$inputKey];
            $this->digests->decideMaterial($id, $decision['status'], $decision['group'], $decision['reason'], $decision['error']);
        }
        foreach ($snapshot['events'] as $index => $event) {
            $this->digests->saveEvent($runId, $index + 1, $event['group'], $event['event'], $materialIds);
        }

        $this->digests->saveArtifacts($runId, $snapshot['artifacts'], $snapshot['email_subject']);

        $connection->executeStatement(<<<'SQL'
            UPDATE runs
            SET status = 'completed', created_at = :created_at, started_at = :created_at,
                finished_at = :completed_at, published_at = CURRENT_TIMESTAMP,
                model_version = :model_version, prompt_version = :prompt_version
            WHERE id = :id
            SQL, [
            'id' => $runId,
            'created_at' => $snapshot['created_at'],
            'completed_at' => $snapshot['completed_at'],
            'model_version' => $snapshot['model_version'],
            'prompt_version' => $snapshot['prompt_version'],
        ]);
    }
}
