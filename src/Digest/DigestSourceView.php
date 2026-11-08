<?php

declare(strict_types=1);

namespace App\Digest;

final readonly class DigestSourceView
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $url,
        public ?string $publishedAt,
    ) {
    }
}
