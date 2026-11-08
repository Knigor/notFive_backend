<?php

declare(strict_types=1);

namespace App\Digest;

final readonly class DigestFilesView
{
    public function __construct(
        public ?string $digest,
        public ?string $post,
        public ?string $email,
        public ?string $csv,
        public ?string $emailSubject,
    ) {
    }
}
