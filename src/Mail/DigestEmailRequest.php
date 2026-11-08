<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class DigestEmailRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'digest_email.subject.required')]
        #[Assert\Length(max: 200, maxMessage: 'digest_email.subject.too_long')]
        public string $subject,
        #[Assert\NotBlank(message: 'digest_email.digest.required')]
        #[Assert\Length(max: 20000, maxMessage: 'digest_email.digest.too_long')]
        public string $digest,
        #[Assert\Email(message: 'digest_email.recipient.invalid')]
        #[Assert\NotBlank(message: 'digest_email.recipient.required', groups: ['send'])]
        public string $recipient,
    ) {
    }
}
