<?php

declare(strict_types=1);

namespace App\Run;

use App\Dataset\DatasetFileReader;
use App\Dataset\DatasetImporter;
use App\Import\ImportRepository;
use App\SiteSource\SiteSource;
use App\SiteSource\SiteSourceSelector;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class RunStarter
{
    public function __construct(
        private DatasetImporter $importer,
        private ImportRepository $repository,
        private SiteSourceSelector $siteSourceSelector,
        private Connection $connection,
    ) {
    }

    /** @param list<string> $sourceIds
     * @return array{runId: string, imported: int, rejected: int, siteCount: int, inputKind: string, dispatchStatus: string, status: string}
     */
    public function start(?UploadedFile $file, array $sourceIds, ?string $controlTime): array
    {
        if (null !== $file && [] !== $sourceIds) {
            throw new \InvalidArgumentException('multiple_inputs');
        }

        $sources = $this->siteSourceSelector->select($sourceIds);
        $normalizedSites = array_map(static fn (SiteSource $source): string => $source->rootUrl(), $sources);

        if (null === $file && null === $controlTime) {
            throw new \InvalidArgumentException('control_time_required');
        }

        $time = null === $controlTime ? null : DatasetFileReader::parseTime($controlTime);

        if (null !== $file) {
            if (!$file->isValid()) {
                throw new \InvalidArgumentException('invalid_file');
            }

            $result = $this->importer->import($file->getPathname(), $time, $file->getClientOriginalName());
            $runId = $result->runId;
            $imported = $result->imported;
            $rejected = $result->failed;
            $runTime = $result->controlTime;
            $inputKind = 'DATASET';
        } else {
            $runTime = $time ?? throw new \InvalidArgumentException('control_time_required');
            $runId = $this->connection->transactional(function () use ($runTime, $normalizedSites, $sources): string {
                $id = $this->repository->createRun($runTime, [
                    'source_urls' => $normalizedSites,
                    'period_start' => $runTime->modify('-24 hours')->format(DATE_ATOM),
                ]);
                foreach ($sources as $source) {
                    $this->repository->createInput($id, 'site', $source->name, $source->taskUrl, null, 'pending', $source->id);
                }

                return $id;
            });
            $imported = 0;
            $rejected = 0;
            $inputKind = [] === $normalizedSites ? 'DEFAULT' : 'SITES';
        }

        return [
            'runId' => $runId,
            'imported' => $imported,
            'rejected' => $rejected,
            'siteCount' => count($sources),
            'inputKind' => strtolower($inputKind),
            'dispatchStatus' => 'pending',
            'status' => 'queued',
        ];
    }
}
