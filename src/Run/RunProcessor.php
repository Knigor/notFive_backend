<?php

declare(strict_types=1);

namespace App\Run;

use App\Dataset\DatasetMaterial;
use App\Digest\DigestBuilder;
use App\Digest\DigestRepository;
use App\Import\ImportRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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

        try {
            $run = $this->digests->run($runId) ?? throw new \UnexpectedValueException('Claimed run is missing.');
            $settings = json_decode((string) $run['settings_snapshot'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($settings)) {
                throw new \UnexpectedValueException('Invalid run settings.');
            }
            if (!array_key_exists('dataset', $settings)) {
                $this->collect($runId, $run, $settings);
            }
            $this->builder->buildClaimed($runId);
        } catch (\Throwable $exception) {
            $this->digests->fail($runId, 'run_processing_failed');
            $this->imports->failPendingInputs($runId);
            $this->logger->error('Digest run failed.', ['runId' => $runId, 'exception' => $exception]);
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
        $collection = $this->go->dispatch($payload) ?? throw new \RuntimeException('Go collection is not configured.');
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
        foreach (GoCollection::articles($collection) as $article) {
            $url = $article['url'] ?? null;
            $host = is_string($url) ? (string) parse_url($url, PHP_URL_HOST) : '';
            $inputId = $this->inputForHost($host, $inputs);
            if (null === $inputId) {
                continue;
            }
            $material = new DatasetMaterial(
                $article['id'] ?? null, $article['title'] ?? null, $article['text'] ?? null,
                $article['source'] ?? null, $url, $article['publishedAt'] ?? null,
                $article['missing'] ?? [], $article['error'] ?? null,
            );
            if (0 !== count($this->validator->validate($material))) {
                continue;
            }
            $this->imports->saveSearchMaterial($runId, $inputId, $material);
        }
        foreach ($inputs as $inputId) {
            $this->imports->updateInput($inputId, 'completed');
        }
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
