<?php

declare(strict_types=1);

namespace App\Mail;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class EmailSubscriptionService
{
    public function __construct(
        private EmailSubscriptionRepository $subscriptions,
        private EmailConfirmationSender $sender,
        private ValidatorInterface $validator,
        private LoggerInterface $logger,
        private Connection $connection,
        private EmailDeliveryDispatcher $dispatcher,
    ) {
    }

    public function subscribe(string $email, string $locale, string $clientIp): void
    {
        $email = strtolower(trim($email));
        $violations = $this->validator->validate($email, [
            new Assert\NotBlank(message: 'email_subscription.email_required'),
            new Assert\Length(max: 254, maxMessage: 'email_subscription.email_too_long'),
            new Assert\Email(message: 'email_subscription.email_invalid'),
        ]);

        if (0 !== count($violations)) {
            throw new EmailSubscriptionException('invalid_email');
        }

        $locale = 'en' === $locale ? 'en' : 'ru';
        if ($this->dispatcher->isQueued()) {
            try {
                // The reservation and queue insert share the same Doctrine connection and commit.
                $this->connection->transactional(function () use ($email, $locale, $clientIp): void {
                    $confirmation = $this->subscriptions->reserveConfirmation($email, $locale, $clientIp);
                    if (null !== $confirmation) {
                        $this->dispatcher->dispatch($confirmation);
                    }
                });
            } catch (\Throwable) {
                $this->logger->warning('Email confirmation queue is unavailable.');
                throw new EmailSubscriptionException('unavailable');
            }

            return;
        }

        $confirmation = $this->subscriptions->reserveConfirmation($email, $locale, $clientIp);
        if (null === $confirmation) {
            return;
        }

        try {
            if ($this->sender->send($confirmation)) {
                return;
            }
        } catch (\Throwable) {
            $this->logger->warning('Email confirmation failed.');
        }

        $this->subscriptions->releaseConfirmation($confirmation);
        throw new EmailSubscriptionException('unavailable');
    }
}
