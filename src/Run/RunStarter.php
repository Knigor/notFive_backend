<?php

declare(strict_types=1);

namespace App\Run;

use App\Dataset\DatasetFileReader;
use App\Dataset\DatasetImporter;
use App\Dataset\DatasetMaterial;
use App\Import\ImportRepository;
use App\SiteSource\SiteSource;
use App\SiteSource\SiteSourceSelector;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class RunStarter
{
    public function __construct(
        private DatasetImporter $importer,
        private ImportRepository $repository,
        private GoRunDispatcher $dispatcher,
        private SiteSourceSelector $siteSourceSelector,
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
            $runId = $this->repository->createRun($runTime, [
                'source_urls' => $normalizedSites,
                'period_start' => $runTime->modify('-24 hours')->format(DATE_ATOM),
            ]);
            $imported = 0;
            $rejected = 0;
            $inputKind = [] === $normalizedSites ? 'DEFAULT' : 'SITES';
        }

        $siteInputIds = [];

        foreach ($sources as $source) {
            $siteInputIds[$source->rootUrl()] = $this->repository->createInput($runId, 'site', $source->name, $source->taskUrl, null, 'pending', $source->id);
        }

        $payload = [
            'kind' => $inputKind,
            'controlTime' => $runTime->format(DATE_ATOM),
        ];

        if ('DATASET' === $inputKind) {
            $payload['items'] = $this->repository->getRunMaterials($runId);
        } elseif ('SITES' === $inputKind) {
            $payload['sites'] = $normalizedSites;
        }

        try {
            $collection = $this->dispatcher->dispatch($payload);
        } catch (\RuntimeException $exception) {
            if ('go_dispatch_failed' !== $exception->getMessage()) {
                throw $exception;
            }

            $this->repository->failRun($runId, 'Go collection failed.');

            foreach ($siteInputIds as $inputId) {
                $this->repository->updateInput($inputId, 'failed', 'Go collection failed.');
            }

            return [
                'runId' => $runId,
                'imported' => $imported,
                'rejected' => $rejected,
                'siteCount' => count($siteInputIds),
                'inputKind' => strtolower($inputKind),
                'dispatchStatus' => 'failed',
                'status' => 'failed',
            ];
        }

        if (null !== $collection) {
            $this->repository->recordGoCollection($runId, $collection);

            if ('DATASET' !== $inputKind) {
                foreach ($collection['sites'] as $site) {
                    if (!isset($siteInputIds[$site])) {
                        $siteInputIds[$site] = $this->repository->createInput($runId, 'site', substr($site, 8), $site, null, 'pending');
                    }
                }

                foreach ($collection['articles'] as $article) {
                    $site = is_string($article['url'] ?? null) ? $this->siteForArticle($article['url'], array_keys($siteInputIds)) : null;

                    if (null === $site) {
                        continue;
                    }

                    $material = new DatasetMaterial($article['id'], $article['title'], $article['text'], $article['source'], $article['url'], $article['publishedAt'], $article['missing'], $article['error']);

                    if ($this->repository->saveSearchMaterial($runId, $siteInputIds[$site], $material)) {
                        ++$imported;
                    }
                }

                foreach ($siteInputIds as $inputId) {
                    $this->repository->updateInput($inputId, 'completed');
                }
            }
        }

        return [
            'runId' => $runId,
            'imported' => $imported,
            'rejected' => $rejected,
            'siteCount' => count($siteInputIds),
            'inputKind' => strtolower($inputKind),
            'dispatchStatus' => null === $collection ? 'pending' : 'completed',
            'status' => 'queued',
        ];
    }

    /** @param list<string> $sites */
    private function siteForArticle(string $url, array $sites): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        foreach ($sites as $site) {
            $siteHost = (string) parse_url($site, PHP_URL_HOST);

            if ($host === $siteHost || str_ends_with($host, '.'.$siteHost)) {
                return $site;
            }
        }

        return null;
    }
}
