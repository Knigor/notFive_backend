<?php

declare(strict_types=1);

namespace App\Mail\Message;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class SendEmailConfirmation
{
    public function __construct(public string $targetId, public string $requestId)
    {
    }
}
