<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

final readonly class DigestEmailSender
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')]
        private string $senderAddress,
    ) {
    }

    public function send(string $recipient, DigestEmailPreview $preview): void
    {
        $email = (new Email())
            ->from($this->senderAddress)
            ->to($recipient)
            ->subject($preview->subject)
            ->text($preview->text)
            ->html($preview->html);

        $this->mailer->send($email);
    }
}
