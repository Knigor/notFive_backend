<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class EmailSubscriptionLinks
{
    public function __construct(
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $baseUri,
    ) {
    }

    public function confirm(string $token): string
    {
        return rtrim($this->baseUri, '/').'/email/confirm?token='.rawurlencode($token);
    }

    public function unsubscribe(string $token): string
    {
        return rtrim($this->baseUri, '/').'/email/unsubscribe?token='.rawurlencode($token);
    }
}
