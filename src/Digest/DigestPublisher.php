<?php

declare(strict_types=1);

namespace App\Digest;

use App\Mail\EmailNewsletterSender;

final readonly class DigestPublisher
{
    public function __construct(private DigestPublicationRepository $repository, private EmailNewsletterSender $sender)
    {
    }

    /** @return array{runId: string, publishedAt: string, alreadyPublished: bool, emailSent: int, emailFailed: int, emailPending: int} */
    public function publish(string $runId): array
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $runId)) {
            throw new \InvalidArgumentException('invalid_run_id');
        }
        $result = $this->repository->publish($runId);

        // Publication and recipient snapshot are committed before any external SMTP call.
        return [...$result, ...$this->sender->send($runId)];
    }
}
