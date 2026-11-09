<?php

declare(strict_types=1);

namespace App\Mail;

use App\Digest\DeliveryRepository;
use App\Digest\PublishedDigestRepository;
use App\Mail\Message\SendNewsletterDelivery;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class EmailNewsletterSender
{
    public function __construct(
        private Connection $connection,
        private DeliveryRepository $repository,
        private PublishedDigestRepository $digests,
        private DigestEmailSender $sender,
        private EmailSubscriptionLinks $links,
        private TranslatorInterface $translator,
        private DigestEmailComposer $composer,
        private EmailDeliveryDispatcher $dispatcher,
    ) {
    }

    /** @return array{emailSent: int, emailFailed: int, emailPending: int} */
    public function send(string $runId): array
    {
        foreach ($this->repository->pending($runId, 'email') as $delivery) {
            if ($this->isQueued()) {
                $this->dispatcher->dispatch(new SendNewsletterDelivery($delivery['id']));
            } else {
                $this->sendDelivery($delivery['id']);
            }
        }

        return $this->counts($runId);
    }

    public function isQueued(): bool
    {
        return $this->dispatcher->isQueued();
    }

    public function sendDelivery(string $deliveryId): bool
    {
        return $this->connection->transactional(function () use ($deliveryId): bool {
            $delivery = $this->connection->fetchAssociative(<<<'SQL'
                SELECT d.target_id, a.run_id FROM deliveries d
                JOIN artifacts a ON a.id = d.artifact_id
                WHERE d.id = :id AND a.kind = 'email_preview' AND d.status IN ('queued', 'failed')
                SQL, ['id' => $deliveryId]);
            if (false === $delivery) {
                return true;
            }

            $digest = $this->digests->find((string) $delivery['run_id']);
            if (null === $digest || null === $digest->subject) {
                return true;
            }

            // Wait for another sender/unsubscribe before checking the delivery. SKIP LOCKED
            // alone would acknowledge a queued message while its send was still in progress.
            $this->connection->fetchOne('SELECT id FROM delivery_targets WHERE id = :id FOR UPDATE', ['id' => $delivery['target_id']]);
            $message = $this->repository->lockMessage($deliveryId, (string) $delivery['target_id'], 'email');
            if (null === $message || null === $message['token']) {
                return true;
            }

            $this->repository->attempt($deliveryId);
            try {
                $url = $this->links->unsubscribe($message['token']);
                $footer = $this->translator->trans('email_newsletter.unsubscribe', locale: $message['locale']);
                $preview = $this->composer->compose(
                    $digest->subject,
                    $digest->content,
                    $message['locale'],
                    $url,
                    $footer,
                );
                $this->sender->send($message['recipient'], $preview);
            } catch (\Throwable) {
                $this->repository->failed($deliveryId, 'email_transport_failed');

                return false;
            }
            $this->repository->sent($deliveryId);

            return true;
        });
    }

    /** @return array{emailSent: int, emailFailed: int, emailPending: int} */
    private function counts(string $runId): array
    {
        $counts = $this->repository->counts($runId, 'email');

        return ['emailSent' => $counts['sent'], 'emailFailed' => $counts['failed'], 'emailPending' => $counts['queued']];
    }
}
