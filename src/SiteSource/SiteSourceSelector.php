<?php

declare(strict_types=1);

namespace App\SiteSource;

final readonly class SiteSourceSelector
{
    public function __construct(private SiteSourceRepository $repository)
    {
    }

    /** @param list<string> $ids
     * @return list<SiteSource>
     */
    public function select(array $ids): array
    {
        if (count($ids) > 5 || count($ids) !== count(array_unique($ids))) {
            throw new \InvalidArgumentException('invalid_sources');
        }

        foreach ($ids as $id) {
            if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id)) {
                throw new \InvalidArgumentException('invalid_sources');
            }
        }

        $sources = $this->repository->readyByIds($ids);

        if (count($sources) !== count($ids)) {
            throw new \InvalidArgumentException('invalid_sources');
        }

        $domains = array_map(static fn (SiteSource $source): string => $source->searchDomain, $sources);

        if (count($domains) !== count(array_unique($domains))) {
            throw new \InvalidArgumentException('invalid_sources');
        }

        return $sources;
    }
}
