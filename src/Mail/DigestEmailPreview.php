<?php

declare(strict_types=1);

namespace App\Mail;

final readonly class DigestEmailPreview
{
    public function __construct(
        public string $subject,
        public string $text,
        public string $html,
    ) {
    }
}
