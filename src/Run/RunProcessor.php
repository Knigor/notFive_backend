<?php

declare(strict_types=1);

namespace App\Run;

use App\Dataset\DatasetFileReader;
use App\Dataset\DatasetImportException;
use App\Dataset\DatasetMaterial;
use App\Digest\DigestBuilder;
use App\Digest\DigestRepository;
use App\Import\ImportRepository;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[WithMonologChannel('digest_run')]
final readonly class RunProcessor
{
    public function __construct(
        private ImportRepository $imports,
        private DigestRepository $digests,
        private GoRunDispatcher $go,
        private DigestBuilder $builder,
        private ValidatorInterface $validator,
        private LoggerInterface $logger,
    ) {
    }

    public function processNext(): bool
    {
        $runId = $this->imports->claimNextRun();
        if (null === $runId) {
            return false;
        }

        $this->logger->info('Digest run started.', ['runId' => $runId]);
        try {
            $run = $this->digests->run($runId) ?? throw new \UnexpectedValueException('Claimed run is missing.');
            $settings = json_decode((string) $run['settings_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($settings)) {
                throw new \UnexpectedValueException('Invalid run settings.');
            }
            if (!array_key_exists('dataset', $settings)) {
                $this->collect($runId, $run, $settings);
            }
            $result = $this->builder->buildClaimed($runId);
            $this->logger->info('Digest run completed.', ['runId' => $runId, 'eventCount' => $result['eventCount'], 'warningCount' => count($result['warnings'])]);
        } catch (\Throwable $exception) {
            $this->digests->fail($runId, 'run_processing_failed');
            $this->imports->failPendingInputs($runId);
            $this->logger->error('Digest run failed.', ['runId' => $runId, 'errorType' => $exception::class]);
        }

        return true;
    }

    /** @param array<string, mixed> $run
     * @param array<string, mixed> $settings
     */
    private function collect(string $runId, array $run, array $settings): void
    {
        $sites = $settings['source_urls'] ?? [];
        if (!is_array($sites) || !array_is_list($sites)) {
            throw new \UnexpectedValueException('Invalid site selection.');
        }
        $payload = [
            'kind' => [] === $sites ? 'DEFAULT' : 'SITES',
            'controlTime' => (new \DateTimeImmutable((string) $run['control_time']))->format(DATE_ATOM),
        ];
        if ([] !== $sites) {
            $payload['sites'] = $sites;
        }
        $collection = $this->go->dispatch($payload, $runId) ?? throw new \RuntimeException('Go collection is not configured.');
        $this->imports->recordGoCollection($runId, $collection);
        $inputs = [];
        foreach ($this->imports->siteInputs($runId) as $input) {
            $inputs[(string) parse_url($input['locator'], PHP_URL_HOST)] = $input['id'];
        }
        foreach (GoCollection::strings($collection, 'sites') as $site) {
            $host = (string) parse_url($site, PHP_URL_HOST);
            if (!isset($inputs[$host])) {
                $inputs[$host] = $this->imports->createInput($runId, 'site', $host, $site, null, 'pending');
            }
        }
        $errors = [];
        $imported = 0;
        foreach (GoCollection::articles($collection) as $article) {
            $url = $article['url'] ?? null;
            $host = is_string($url) ? (string) parse_url($url, PHP_URL_HOST) : '';
            $inputId = $this->inputForHost($host, $inputs);
            if (null === $inputId) {
                $errors[] = $this->importError($article, 'source_host_mismatch');
                continue;
            }
            $material = new DatasetMaterial(
                $article['id'] ?? null, $article['title'] ?? null, $article['text'] ?? null,
                $article['source'] ?? null, $url, $article['publishedAt'] ?? null,
                $article['missing'] ?? [], $article['error'] ?? null,
            );
            if (0 !== count($this->validator->validate($material))) {
                $errors[] = $this->importError($article, 'invalid_material');
                continue;
            }
            try {
                if (is_string($material->publishedAt) && '' !== $material->publishedAt) {
                    DatasetFileReader::parseTime($material->publishedAt);
                }
            } catch (DatasetImportException) {
                $errors[] = $this->importError($article, 'invalid_publication_date');
                continue;
            }
            if ($this->imports->saveSearchMaterial($runId, $inputId, $material)) {
                ++$imported;
            }
        }
        $this->imports->recordCollectionImportErrors($runId, $errors);
        $this->logger->info('Digest collection imported.', [
            'runId' => $runId, 'collectionRunId' => $collection['runId'] ?? null,
            'collected' => count(GoCollection::articles($collection)), 'imported' => $imported, 'importRejected' => count($errors),
            'collectionExcluded' => is_array($collection['excluded'] ?? null) ? count($collection['excluded']) : 0,
        ]);
        foreach ($inputs as $inputId) {
            $this->imports->updateInput($inputId, 'completed');
        }
    }

    /** @param array<string, mixed> $article
     * @return array<string, mixed>
     */
    private function importError(array $article, string $reason): array
    {
        return [
            'id' => is_string($article['id'] ?? null) && '' !== $article['id'] ? $article['id'] : 'import-'.substr(hash('sha256', json_encode($article, JSON_THROW_ON_ERROR)), 0, 16),
            'url' => $article['url'] ?? null, 'title' => $article['title'] ?? null,
            'reason' => $reason, 'error' => $reason,
        ];
    }

    /** @param array<string, string> $inputs */
    private function inputForHost(string $host, array $inputs): ?string
    {
        foreach ($inputs as $siteHost => $inputId) {
            if ($host === $siteHost || str_ends_with($host, '.'.$siteHost)) {
                return $inputId;
            }
        }

        return null;
    }
}
