<?php

declare(strict_types=1);

namespace App\Mail;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final readonly class EmailNewsletterSender
{
    public function __construct(
        private Connection $connection,
        private EmailNewsletterRepository $repository,
        private DigestEmailSender $sender,
        private EmailSubscriptionLinks $links,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    /** @return array{emailSent: int, emailFailed: int, emailPending: int} */
    public function send(string $runId): array
    {
        foreach ($this->repository->pending($runId) as $delivery) {
            $this->connection->transactional(function () use ($delivery): void {
                $message = $this->repository->lockMessage($delivery['id'], $delivery['targetId']);
                if (null === $message) {
                    return;
                }

                try {
                    $url = $this->links->unsubscribe($message['token']);
                    $footer = $this->translator->trans('email_newsletter.unsubscribe', locale: $message['locale']);
                    $preview = new DigestEmailPreview(
                        $message['subject'],
                        $message['content']."\n\n".$footer.': '.$url,
                        $this->twig->render('mail/newsletter.html.twig', [
                            'subject' => $message['subject'], 'content' => $message['content'],
                            'locale' => $message['locale'], 'unsubscribe_url' => $url,
                        ]),
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
