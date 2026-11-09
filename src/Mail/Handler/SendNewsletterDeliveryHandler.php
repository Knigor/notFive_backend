<?php

declare(strict_types=1);

namespace App\Mail\Handler;

use App\Mail\EmailNewsletterSender;
use App\Mail\Message\SendNewsletterDelivery;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SendNewsletterDeliveryHandler
{
    public function __construct(private EmailNewsletterSender $sender)
    {
    }

    public function __invoke(SendNewsletterDelivery $message): void
    {
        // sendDelivery commits the failed attempt before Messenger schedules a retry.
        if (!$this->sender->sendDelivery($message->deliveryId)) {
            throw new \RuntimeException('Email newsletter transport failed.');
        }
    }
}
