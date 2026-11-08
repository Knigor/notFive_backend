<?php

declare(strict_types=1);

namespace App\Run;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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

    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $logger,
        private string $goServiceUrl,
        private string $digestServiceToken,
    ) {
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>|null
     */
    public function dispatch(array $input): ?array
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
                'status' => $response->getStatusCode(),
            ]);
        } catch (TransportExceptionInterface|\JsonException $exception) {
            $this->logger->warning('Go GraphQL service did not return a valid collection.', [
                'exception' => $exception,
            ]);
        }

        throw new \RuntimeException('go_dispatch_failed');
    }
}
