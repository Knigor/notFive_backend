<?php

declare(strict_types=1);

namespace App\Digest;

final readonly class DigestView
{
    /** @param list<DigestEventView> $events */
    public function __construct(
        public string $runId,
        public string $controlTime,
        public string $periodStart,
        public string $timezone,
        public string $language,
        public string $publishedAt,
        public int $totalMaterials,
        public array $events,
        public ?DigestFilesView $files,
    ) {
    }
}
