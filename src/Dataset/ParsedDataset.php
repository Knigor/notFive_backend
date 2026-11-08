<?php

declare(strict_types=1);

namespace App\Dataset;

final readonly class ParsedDataset
{
    /** @param list<mixed> $rows */
    public function __construct(
        public array $rows,
        public ?\DateTimeImmutable $controlTime,
        public ?\DateTimeImmutable $periodStart,
        public ?string $datasetName,
    ) {
    }
}
