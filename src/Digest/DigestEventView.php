<?php

declare(strict_types=1);

namespace App\Digest;

final readonly class DigestEventView
{
    /** @param list<DigestSourceView> $sources */
    public function __construct(
        public string $id,
        public int $rank,
        public string $title,
        public string $summary,
        public string $consequences,
        public string $selectionReason,
        public array $sources,
    ) {
    }
}
