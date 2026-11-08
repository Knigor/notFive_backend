<?php

declare(strict_types=1);

namespace App\Telegram;

final readonly class PublishedEvent
{
    /** @param list<string> $sources */
    public function __construct(
        public string $id,
        public int $rank,
        public string $title,
        public string $summary,
        public string $consequences,
        public array $sources,
    ) {
    }
}
