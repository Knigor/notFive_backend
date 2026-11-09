<?php

declare(strict_types=1);

namespace App\Mail\Handler;

use App\Mail\EmailConfirmationSender;
use App\Mail\Message\SendEmailConfirmation;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendEmailConfirmationHandler
{
    public function __construct(private EmailConfirmationSender $sender)
    {
    }

    public function __invoke(SendEmailConfirmation $message): void
    {
        if (!$this->sender->send($message)) {
            throw new \RuntimeException('Email confirmation transport failed.');
        }
    }
}
