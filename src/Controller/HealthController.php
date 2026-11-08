<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController
{
    #[Route('/healthz', name: 'health', methods: ['GET'])]
    #[OA\Get(
        summary: 'Check application and database availability',
        tags: ['Service'],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Application and database are available.',
                content: new OA\JsonContent(
                    required: ['status'],
                    properties: [new OA\Property(property: 'status', type: 'string', example: 'ok')],
                ),
            ),
            new OA\Response(
                response: 503,
                description: 'Database is unavailable.',
                content: new OA\JsonContent(
                    required: ['status'],
                    properties: [new OA\Property(property: 'status', type: 'string', example: 'unavailable')],
                ),
            ),
        ],
    )]
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
