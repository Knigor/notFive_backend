<?php

declare(strict_types=1);

namespace App\Run;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/** Reads existing audit files only; never invokes generation or follows provider URLs. */
final readonly class RunStatisticsAuditReader
{
    private const int MAX_BYTES = 16 * 1024 * 1024;

    public function __construct(private HttpClientInterface $client, private string $goServiceUrl, private string $digestServiceToken)
    {
    }

    /** @return array<string, mixed>|null */
    public function read(mixed $runId): ?array
    {
        if (!is_string($runId) || 1 !== preg_match('/^[a-f0-9]{32}$/D', $runId) || '' === $this->goServiceUrl || '' === $this->digestServiceToken) {
            return null;
        }

        $response = null;
        try {
            $response = $this->client->request('GET', rtrim($this->goServiceUrl, '/').'/v1/runs/'.$runId, [
                'auth_bearer' => $this->digestServiceToken,
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 3, 'max_duration' => 5, 'max_redirects' => 0, 'buffer' => false,
            ]);
            if (200 !== $response->getStatusCode()) {
                return null;
            }
            $body = '';
            foreach ($this->client->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    return null;
                }
                $content = $chunk->getContent();
                if (strlen($body) + strlen($content) > self::MAX_BYTES) {
                    return null;
                }
                $body .= $content;
            }
            $audit = json_decode($body, true, 64, JSON_THROW_ON_ERROR);

            return is_array($audit) && ($audit['id'] ?? null) === $runId ? $audit : null;
        } catch (\Throwable) {
            // Exceptions can contain provider content, request URLs and credentials.
            return null;
        } finally {
            $response?->cancel();
        }
    }
}
