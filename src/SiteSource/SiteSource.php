<?php

declare(strict_types=1);

namespace App\SiteSource;

final readonly class SiteSource
{
    public function __construct(
        public string $id,
        public string $name,
        public string $taskUrl,
        public string $searchDomain,
        public string $status,
    ) {
    }

    public function rootUrl(): string
    {
        return 'https://'.$this->searchDomain;
    }
}
