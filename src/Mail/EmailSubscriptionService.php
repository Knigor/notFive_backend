<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class EmailSubscriptionService
{
    public function __construct(
        private EmailSubscriptionRepository $subscriptions,
        private EmailSubscriptionComposer $composer,
        private DigestEmailSender $sender,
        private ValidatorInterface $validator,
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
        $token = $this->subscriptions->reserveConfirmation($email, $locale, $clientIp);

        if (null === $token) {
            return;
        }

        try {
            $this->sender->send($email, $this->composer->confirmation($token, $locale));
        } catch (\Throwable) {
            $this->subscriptions->releaseConfirmation($token);
            throw new EmailSubscriptionException('unavailable');
        }
    }
}
