<?php

declare(strict_types=1);

namespace App\Security;

use Doctrine\DBAL\Connection;

final readonly class AdminUserRepository
{
    public function __construct(private Connection $connection)
    {
    }

    public function findActiveByUsername(string $username): ?AdminUser
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, username, password_hash FROM admin_users WHERE username = :username AND is_active = TRUE',
            ['username' => $username],
        );

        if (false === $row) {
            return null;
        }

        return new AdminUser((string) $row['id'], (string) $row['username'], (string) $row['password_hash']);
    }

    public function create(string $username, string $passwordHash): void
    {
        $this->connection->insert('admin_users', [
            'id' => $this->connection->fetchOne('SELECT gen_random_uuid()'),
            'username' => $username,
            'password_hash' => $passwordHash,
        ]);
    }

    public function provision(string $username, string $passwordHash): void
    {
        $this->connection->executeStatement(
            'INSERT INTO admin_users (id, username, password_hash, is_active) VALUES (gen_random_uuid(), :username, :hash, TRUE)
             ON CONFLICT (username) DO UPDATE SET password_hash = EXCLUDED.password_hash, is_active = TRUE',
            ['username' => $username, 'hash' => $passwordHash],
        );
    }
}
