<?php

declare(strict_types=1);

namespace App\Mail;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class DigestEmailRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 200)]
        public string $subject,
        #[Assert\NotBlank]
        #[Assert\Length(max: 20000)]
        public string $digest,
        #[Assert\Email]
        #[Assert\NotBlank(groups: ['send'])]
        public string $recipient,
    ) {
    }
}
