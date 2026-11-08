<?php

declare(strict_types=1);

namespace App\Telegram;

final readonly class PublishedDigest
{
    /** @param list<PublishedEvent> $events */
    public function __construct(
        public string $artifactId,
        public string $content,
        public array $events,
    ) {
    }
}
