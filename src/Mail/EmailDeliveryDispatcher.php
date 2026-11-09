<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Message\SendEmailConfirmation;
use App\Mail\Message\SendNewsletterDelivery;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class EmailDeliveryDispatcher
{
    public function __construct(
        private MessageBusInterface $bus,
        #[Autowire('%env(EMAIL_DELIVERY_MODE)%')]
        private string $mode,
    ) {
        if (!in_array($mode, ['sync', 'queued'], true)) {
            throw new \InvalidArgumentException('Invalid email delivery mode.');
        }
    }

    public function isQueued(): bool
    {
        return 'queued' === $this->mode;
    }

    public function dispatch(SendEmailConfirmation|SendNewsletterDelivery $message): void
    {
        $this->bus->dispatch($message);
    }
}
