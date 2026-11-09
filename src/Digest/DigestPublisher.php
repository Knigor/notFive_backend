<?php

declare(strict_types=1);

namespace App\Digest;

use App\Mail\EmailNewsletterSender;
use App\Telegram\TelegramNewsletterSender;
use Doctrine\DBAL\Connection;

final readonly class DigestPublisher
{
    public function __construct(
        private Connection $connection,
        private DigestPublicationRepository $repository,
        private EmailNewsletterSender $sender,
        private TelegramNewsletterSender $telegram,
    ) {
    }

    /** @return array{runId: string, publishedAt: string, alreadyPublished: bool, emailSent: int, emailFailed: int, emailPending: int} */
    public function publish(string $runId): array
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $runId)) {
            throw new \InvalidArgumentException('invalid_run_id');
        }
        if ($this->sender->isQueued()) {
            // The publication, recipient snapshot and queue jobs commit together.
            $result = $this->connection->transactional(fn (): array => [
                ...$this->repository->publish($runId),
                ...$this->sender->send($runId),
            ]);
        } else {
            // Synchronous local delivery starts after publication is committed.
            $result = [...$this->repository->publish($runId), ...$this->sender->send($runId)];
        }
        $this->telegram->send($runId);

        return $result;
    }
}
