<?php

declare(strict_types=1);

namespace App\Mail;

use App\Digest\DeliveryRepository;
use App\Mail\Message\SendEmailConfirmation;
use Doctrine\DBAL\Connection;

final readonly class EmailSubscriptionRepository
{
    public function __construct(
        private Connection $connection,
        private DeliveryRepository $deliveries,
        private EmailConfirmationToken $tokens,
    ) {
    }

    public function reserveConfirmation(string $email, string $locale, string $clientIp): ?SendEmailConfirmation
    {
        return $this->connection->transactional(function () use ($email, $locale, $clientIp): ?SendEmailConfirmation {
            // Serialize requests for the same address even before it has a row.
            $this->connection->fetchOne(
                "SELECT pg_advisory_xact_lock(hashtextextended('email_subscription:' || :email, 0))",
                ['email' => $email],
            );

            $target = $this->connection->fetchAssociative(<<<'SQL'
                SELECT id, status,
                    CASE WHEN email_confirmation_sent_at > CURRENT_TIMESTAMP - INTERVAL '60 seconds' THEN 1 ELSE 0 END AS cooling_down
                FROM delivery_targets
                WHERE channel = 'email' AND destination = :email
                FOR UPDATE
                SQL, ['email' => $email]);

            if (false !== $target && ('active' === $target['status'] || 1 === (int) $target['cooling_down'])) {
                return null;
            }

            if (!$this->reserveSend($clientIp)) {
                return null;
            }

            $targetId = false === $target ? (string) $this->connection->fetchOne('SELECT gen_random_uuid()') : (string) $target['id'];
            $confirmation = new SendEmailConfirmation($targetId, bin2hex(random_bytes(32)));
            $this->connection->executeStatement(<<<'SQL'
                INSERT INTO delivery_targets (id, channel, destination, status, locale,
                    email_confirmation_token_hash, email_confirmation_expires_at, email_confirmation_sent_at,
                    email_confirmation_request_id, email_confirmation_delivered_at)
                VALUES (:id, 'email', :email, 'pending', :locale,
                    :token_hash, CURRENT_TIMESTAMP + INTERVAL '24 hours', CURRENT_TIMESTAMP, :request_id, NULL)
                ON CONFLICT (channel, destination) DO UPDATE
                SET status = 'pending', locale = EXCLUDED.locale, confirmed_at = NULL,
                    email_confirmation_token_hash = EXCLUDED.email_confirmation_token_hash,
                    email_confirmation_expires_at = EXCLUDED.email_confirmation_expires_at,
                    email_confirmation_sent_at = EXCLUDED.email_confirmation_sent_at,
                    email_confirmation_request_id = EXCLUDED.email_confirmation_request_id,
                    email_confirmation_delivered_at = NULL
                SQL, [
                'id' => $targetId,
                'email' => $email,
                'locale' => $locale,
                'token_hash' => hash('sha256', $this->tokens->create($confirmation)),
                'request_id' => $confirmation->requestId,
            ]);

            return $confirmation;
        });
    }

    public function releaseConfirmation(SendEmailConfirmation $confirmation): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE delivery_targets
            SET email_confirmation_token_hash = NULL, email_confirmation_expires_at = NULL, email_confirmation_sent_at = NULL,
                email_confirmation_request_id = NULL, email_confirmation_delivered_at = NULL
            WHERE id = :id AND channel = 'email' AND status = 'pending' AND email_confirmation_request_id = :request_id
            SQL, ['id' => $confirmation->targetId, 'request_id' => $confirmation->requestId]);
    }

    /** @return array{recipient: string, locale: string}|null */
    public function lockConfirmation(SendEmailConfirmation $confirmation): ?array
    {
        // A blocking lock prevents a competing worker from acknowledging an unfinished send.
        $target = $this->connection->fetchAssociative(<<<'SQL'
            SELECT destination, locale, email_confirmation_token_hash FROM delivery_targets
            WHERE id = :id AND channel = 'email' AND status = 'pending'
                AND email_confirmation_request_id = :request_id AND email_confirmation_delivered_at IS NULL
                AND email_confirmation_expires_at > CURRENT_TIMESTAMP
            FOR UPDATE
            SQL, ['id' => $confirmation->targetId, 'request_id' => $confirmation->requestId]);
        if (false === $target) {
            return null;
        }

        if (!hash_equals((string) $target['email_confirmation_token_hash'], hash('sha256', $this->tokens->create($confirmation)))) {
            throw new \RuntimeException('Email confirmation signing key does not match the reservation.');
        }

        return ['recipient' => (string) $target['destination'], 'locale' => (string) $target['locale']];
    }

    public function confirmationDelivered(SendEmailConfirmation $confirmation): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE delivery_targets SET email_confirmation_delivered_at = CURRENT_TIMESTAMP
            WHERE id = :id AND email_confirmation_request_id = :request_id
            SQL, ['id' => $confirmation->targetId, 'request_id' => $confirmation->requestId]);
    }

    public function confirmationState(string $token): string
    {
        if (!$this->validToken($token)) {
            return 'invalid';
        }

        $status = $this->connection->fetchOne(<<<'SQL'
            SELECT status FROM delivery_targets
            WHERE channel = 'email' AND email_confirmation_token_hash = :token_hash
                AND (status = 'active' OR (status = 'pending' AND email_confirmation_expires_at > CURRENT_TIMESTAMP))
            SQL, ['token_hash' => hash('sha256', $token)]);

        return is_string($status) ? $status : 'invalid';
    }

    public function confirm(string $token): bool
    {
        if (!$this->validToken($token)) {
            return false;
        }

        $updated = $this->connection->executeStatement(<<<'SQL'
            UPDATE delivery_targets
            SET status = 'active', confirmed_at = CURRENT_TIMESTAMP, email_confirmation_expires_at = NULL,
                email_unsubscribe_token = :unsubscribe_token
            WHERE channel = 'email' AND status = 'pending' AND email_confirmation_token_hash = :token_hash
                AND email_confirmation_expires_at > CURRENT_TIMESTAMP
            SQL, ['token_hash' => hash('sha256', $token), 'unsubscribe_token' => bin2hex(random_bytes(32))]);

        return 1 === $updated || 'active' === $this->confirmationState($token);
    }

    public function unsubscribeState(string $token): string
    {
        if (!$this->validToken($token)) {
            return 'invalid';
        }

        $status = $this->connection->fetchOne(<<<'SQL'
            SELECT status FROM delivery_targets
            WHERE channel = 'email' AND email_unsubscribe_token = :token
            SQL, ['token' => $token]);

        return is_string($status) ? $status : 'invalid';
    }

    public function unsubscribe(string $token): bool
    {
        if (!$this->validToken($token)) {
            return false;
        }

        return $this->connection->transactional(function () use ($token): bool {
            // Lock the recipient before deliveries, in the same order as the newsletter sender.
            $id = $this->connection->fetchOne(<<<'SQL'
                SELECT id FROM delivery_targets
                WHERE channel = 'email' AND email_unsubscribe_token = :token
                FOR UPDATE
                SQL, ['token' => $token]);

            if (!is_string($id)) {
                return false;
            }

            $this->connection->executeStatement(<<<'SQL'
                UPDATE delivery_targets
                SET status = 'unsubscribed', email_confirmation_token_hash = NULL,
                    email_confirmation_expires_at = NULL, email_confirmation_sent_at = NULL,
                    email_confirmation_request_id = NULL, email_confirmation_delivered_at = NULL
                WHERE id = :id
                SQL, ['id' => $id]);
            $this->deliveries->cancelPending($id);

            return true;
        });
    }

    private function reserveSend(string $clientIp): bool
    {
        $attempt = $this->connection->fetchOne(<<<'SQL'
            INSERT INTO email_subscription_limits (ip_hash, window_start, attempts)
            VALUES (:ip_hash, CURRENT_TIMESTAMP, 1)
            ON CONFLICT (ip_hash) DO UPDATE
            SET attempts = CASE
                    WHEN email_subscription_limits.window_start <= CURRENT_TIMESTAMP - INTERVAL '10 minutes' THEN 1
                    ELSE email_subscription_limits.attempts + 1
                END,
                window_start = CASE
                    WHEN email_subscription_limits.window_start <= CURRENT_TIMESTAMP - INTERVAL '10 minutes' THEN CURRENT_TIMESTAMP
                    ELSE email_subscription_limits.window_start
                END
            WHERE email_subscription_limits.window_start <= CURRENT_TIMESTAMP - INTERVAL '10 minutes'
                OR email_subscription_limits.attempts < 5
            RETURNING attempts
            SQL, ['ip_hash' => hash('sha256', $clientIp)]);

        return false !== $attempt;
    }

    private function validToken(string $token): bool
    {
        return 1 === preg_match('/\A[a-f0-9]{64}\z/', $token);
    }
}
