<?php

declare(strict_types=1);

namespace App\Dataset;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class DatasetMaterial
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Type('string')]
        public mixed $inputId,
        #[Assert\Type('string')]
        public mixed $title,
        #[Assert\Type('string')]
        public mixed $body,
        #[Assert\Type('string')]
        public mixed $sourceName,
        #[Assert\Type('string')]
        public mixed $url,
        #[Assert\Type('string')]
        public mixed $publishedAt,
        #[Assert\Type('array')]
        #[Assert\All([new Assert\Type('string')])]
        public mixed $missing,
        #[Assert\Type('string')]
        public mixed $sourceError,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            $row['id'] ?? null,
            $row['title'] ?? null,
            $row['text'] ?? null,
            $row['source'] ?? null,
            $row['url'] ?? null,
            $row['published_at'] ?? null,
            $row['missing'] ?? [],
            $row['error'] ?? null,
        );
    }
}
