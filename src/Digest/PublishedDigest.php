<?php

declare(strict_types=1);

namespace App\Digest;

final readonly class PublishedDigest
{
    /** @param list<PublishedEvent> $events */
    public function __construct(
        public ?string $postArtifactId,
        public ?string $subject,
        public string $content,
        public array $events,
    ) {
    }
}
