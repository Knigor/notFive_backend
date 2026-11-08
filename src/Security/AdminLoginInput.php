<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class AdminLoginInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex('/^[a-z][a-z0-9_]{2,63}$/')]
        public string $username,
        #[Assert\NotBlank]
        #[Assert\Length(min: 12, max: 4096)]
        public string $password,
    ) {
    }
}
