<?php

declare(strict_types=1);

namespace App\GraphQL\Query;

use App\Run\RunStatistics;
use App\Security\RequireAdmin;
use Overblog\GraphQLBundle\Definition\Resolver\QueryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class RunStatisticsQuery implements QueryInterface
{
    public function __construct(private RunStatistics $statistics, private RequireAdmin $requireAdmin, private RequestStack $requestStack)
    {
    }

    /** @return array<string, mixed>|null */
    public function runStatistics(string $runId): ?array
    {
        $this->requireAdmin->check();
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $runId)) {
            return null;
        }

        return $this->statistics->forRun($runId, $this->requestStack->getCurrentRequest()?->getPreferredLanguage(['ru', 'en']) ?? 'ru');
    }
}
