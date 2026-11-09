<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final readonly class DigestEmailSender
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(MAIL_FROM_ADDRESS)%')]
        private string $senderAddress,
        #[Autowire('%env(MAILER_DSN)%')]
        private string $mailerDsn,
    ) {
    }

    public function send(string $recipient, DigestEmailPreview $preview): void
    {
        if ('' === trim($this->mailerDsn) || str_contains(strtolower($this->mailerDsn), 'null://')) {
            throw new \RuntimeException('Email transport is disabled.');
        }
        $email = (new Email())
            ->from(new Address($this->senderAddress, 'notFive'))
            ->to($recipient)
            ->subject($preview->subject)
            ->text($preview->text)
            ->html($preview->html);

        $this->mailer->send($email);
    }
}
