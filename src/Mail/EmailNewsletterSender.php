<?php

declare(strict_types=1);

namespace App\Mail;

use App\Digest\PublishedDigestRepository;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class EmailNewsletterSender
{
    public function __construct(
        private Connection $connection,
        private EmailNewsletterRepository $repository,
        private PublishedDigestRepository $digests,
        private DigestEmailSender $sender,
        private EmailSubscriptionLinks $links,
        private TranslatorInterface $translator,
        private DigestEmailComposer $composer,
    ) {
    }

    /** @return array{emailSent: int, emailFailed: int, emailPending: int} */
    public function send(string $runId): array
    {
        $digest = $this->digests->find($runId);
        if (null === $digest || null === $digest->subject) {
            return $this->repository->counts($runId);
        }

        foreach ($this->repository->pending($runId) as $delivery) {
            $this->connection->transactional(function () use ($delivery, $digest): void {
                $message = $this->repository->lockMessage($delivery['id'], $delivery['targetId']);
                if (null === $message) {
                    return;
                }

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
                    $this->repository->failed($delivery['id']);

                    return;
                }
                $this->repository->sent($delivery['id']);
            });
        }

        return $this->repository->counts($runId);
    }
}
