<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Message\SendEmailConfirmation;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class EmailConfirmationToken
{
    public function __construct(#[Autowire('%kernel.secret%')] private string $secret)
    {
    }

    public function create(SendEmailConfirmation $confirmation): string
    {
        if ('' === $this->secret) {
            throw new \RuntimeException('Email confirmation signing key is missing.');
        }

        // A retry recreates the same link without storing its bearer token in the queue.
        return hash_hmac('sha256', 'notfive-email-confirmation:v1:'.$confirmation->targetId.':'.$confirmation->requestId, $this->secret);
    }
}
