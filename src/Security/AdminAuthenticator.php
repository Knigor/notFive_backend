<?php

declare(strict_types=1);

namespace App\Security;

use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class AdminAuthenticator
{
    public function __construct(
        private AdminUserProvider $provider,
        private UserPasswordHasherInterface $passwordHasher,
        private JWTTokenManagerInterface $jwtManager,
        private ValidatorInterface $validator,
        private int $tokenTtl,
    ) {
    }

    /** @return array{token: string, expiresIn: int} */
    public function login(string $username, string $password): array
    {
        $input = new AdminLoginInput(trim($username), $password);

        if (0 !== count($this->validator->validate($input))) {
            throw new \InvalidArgumentException('invalid_input');
        }

        try {
            $user = $this->provider->loadUserByIdentifier($input->username);
        } catch (UserNotFoundException) {
            throw new \InvalidArgumentException('invalid_credentials');
        }

        if (!$this->passwordHasher->isPasswordValid($user, $input->password)) {
            throw new \InvalidArgumentException('invalid_credentials');
        }

        return ['token' => $this->jwtManager->create($user), 'expiresIn' => $this->tokenTtl];
    }
}
