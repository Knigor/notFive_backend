<?php

declare(strict_types=1);

namespace App\Run;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[WithMonologChannel('digest_run')]
final readonly class GoRunDispatcher
{
    private const DOCUMENT = <<<'GRAPHQL'
        mutation Collect($input: CollectInput!) {
          collect(input: $input) {
            runId
            mode
            sites
            articles { id title text source url publishedAt missing error dateEvidence textTruncated }
            excluded { id url reason }
            warnings
            researchJson
          }
        }
        GRAPHQL;

    private const PREPARE_DOCUMENT = <<<'GRAPHQL'
        mutation PrepareDigest($input: PrepareDigestInput!) {
          prepareDigest(input: $input) {
            runId
            groups { id name ids selectionReason facts { statement sourceIds evidence { articleId quote } } contradictions { description sourceIds } }
            recommendedOrder
            excluded { articleId reason }
            events { groupId title summary consequences post reason }
            selectionSummary warnings
            analysisModel draftModel analysisPromptVersion draftPromptVersion
            analysisUsageJson draftUsageJson analysisDurationMs draftDurationMs
          }
        }
        GRAPHQL;

    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $logger,
        private string $goServiceUrl,
        private string $digestServiceToken,
    ) {
    }

    /** @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function prepareDigest(string $controlTime, array $items, ?string $runId = null): array
    {
        if ('' === $this->goServiceUrl || '' === $this->digestServiceToken) {
            throw new \RuntimeException('Go digest service is not configured.');
        }

        try {
            $response = $this->client->request('POST', rtrim($this->goServiceUrl, '/').'/graphql', [
                'auth_bearer' => $this->digestServiceToken,
                'json' => ['query' => self::PREPARE_DOCUMENT, 'variables' => ['input' => ['controlTime' => $controlTime, 'items' => $items]]],
                'timeout' => 400,
                'max_duration' => 420,
            ]);
            $body = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);

            if (200 === $response->getStatusCode() && is_array($body) && [] === ($body['errors'] ?? []) && is_array($body['data']['prepareDigest'] ?? null)) {
                return $body['data']['prepareDigest'];
            }
            $this->logger->warning('Go digest preparation returned an invalid response.', ['runId' => $runId, 'status' => $response->getStatusCode()]);
        } catch (TransportExceptionInterface|\JsonException $exception) {
            $this->logger->warning('Go digest preparation failed.', ['runId' => $runId, 'errorType' => $exception::class]);
        }

        throw new \RuntimeException('go_digest_failed');
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function dispatch(array $input, ?string $runId = null): ?array
    {
        if ('' === $this->goServiceUrl || '' === $this->digestServiceToken) {
            return null;
        }

        try {
            $response = $this->client->request('POST', rtrim($this->goServiceUrl, '/').'/graphql', [
                'auth_bearer' => $this->digestServiceToken,
                'json' => ['query' => self::DOCUMENT, 'variables' => ['input' => $input]],
                'timeout' => 200,
                'max_duration' => 210,
            ]);

            $body = json_decode($response->getContent(false), true, 512, JSON_THROW_ON_ERROR);

            if (200 === $response->getStatusCode() && is_array($body) && [] === ($body['errors'] ?? []) && is_array($body['data']['collect'] ?? null)) {
                return $body['data']['collect'];
            }

            $this->logger->warning('Go GraphQL service rejected collection with HTTP {status}.', [
                'runId' => $runId, 'status' => $response->getStatusCode(),
            ]);
        } catch (TransportExceptionInterface|\JsonException $exception) {
            $this->logger->warning('Go GraphQL service did not return a valid collection.', [
                'runId' => $runId, 'errorType' => $exception::class,
            ]);
        }

        throw new \RuntimeException('go_dispatch_failed');
    }
}
