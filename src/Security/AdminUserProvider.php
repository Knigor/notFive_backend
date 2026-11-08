<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/** @implements UserProviderInterface<AdminUser> */
final readonly class AdminUserProvider implements UserProviderInterface
{
    public function __construct(private AdminUserRepository $repository)
    {
    }

    public function loadUserByIdentifier(string $identifier): AdminUser
    {
        $user = $this->repository->findActiveByUsername($identifier);

        if (null === $user) {
            throw new UserNotFoundException();
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): AdminUser
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return is_a($class, AdminUser::class, true);
    }
}
