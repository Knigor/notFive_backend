<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/healthz', name: 'health', methods: ['GET'])]
    public function __invoke(Connection $connection, LoggerInterface $logger): JsonResponse
    {
        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable $exception) {
            $logger->error('Database health check failed.', ['exception' => $exception]);

            return new JsonResponse(['status' => 'unavailable'], 503, ['Cache-Control' => 'no-store']);
        }

        return new JsonResponse(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }
}
