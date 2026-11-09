<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Message\SendEmailConfirmation;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final readonly class EmailConfirmationSender
{
    public function __construct(
        private Connection $connection,
        private EmailSubscriptionRepository $subscriptions,
        private EmailConfirmationToken $tokens,
        private EmailSubscriptionComposer $composer,
        private DigestEmailSender $sender,
        private LoggerInterface $logger,
    ) {
    }

    public function send(SendEmailConfirmation $confirmation): bool
    {
        return $this->connection->transactional(function () use ($confirmation): bool {
            $target = $this->subscriptions->lockConfirmation($confirmation);
            if (null === $target) {
                return true;
            }

            try {
                $preview = $this->composer->confirmation($this->tokens->create($confirmation), $target['locale']);
                $this->sender->send($target['recipient'], $preview);
            } catch (\Throwable) {
                // Never attach the transport exception: its debug data can contain credentials.
                $this->logger->warning('Email confirmation transport failed.');

                return false;
            }

            $this->subscriptions->confirmationDelivered($confirmation);

            return true;
        });
    }
}
