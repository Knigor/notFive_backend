<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class AdminUser implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct(
        public string $id,
        public string $username,
        private string $passwordHash,
    ) {
    }

    public function getRoles(): array
    {
        return ['ROLE_ADMIN'];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function getUserIdentifier(): string
    {
        if ('' === $this->username) {
            throw new \LogicException('Administrator username must not be empty.');
        }

        return $this->username;
    }

    public function eraseCredentials(): void
    {
    }
}
