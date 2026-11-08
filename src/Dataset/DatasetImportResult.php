<?php

declare(strict_types=1);

namespace App\Dataset;

final readonly class DatasetImportResult
{
    public function __construct(
        public string $runId,
        public int $imported,
        public int $failed,
        public \DateTimeImmutable $controlTime,
    ) {
    }
}
