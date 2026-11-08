<?php

declare(strict_types=1);

namespace App\GraphQL\Query;

use App\Security\RequireAdmin;
use App\SiteSource\SiteSource;
use App\SiteSource\SiteSourceRepository;
use Overblog\GraphQLBundle\Definition\Resolver\QueryInterface;

final readonly class AvailableSiteSourcesQuery implements QueryInterface
{
    public function __construct(private SiteSourceRepository $repository, private RequireAdmin $requireAdmin)
    {
    }

    /** @return list<SiteSource> */
    public function availableSiteSources(): array
    {
        $this->requireAdmin->check();

        return $this->repository->all();
    }
}
