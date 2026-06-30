<?php

declare(strict_types=1);

namespace App\Domain\User\Repository;

use App\Factory\QueryFactory;

/**
 * User repository (CakePHP query builder).
 */
final class UserRepository
{
    public function __construct(private QueryFactory $queryFactory)
    {
    }

    /**
     * @return array{id: int, username: string, passwordHash: string}|null
     */
    public function findByUsername(string $username): ?array
    {
        $row = $this->queryFactory->newSelect('User')
            ->select(['id' => 'Id', 'username' => 'Username', 'passwordHash' => 'PasswordHash'])
            ->where(['Username' => $username])
            ->execute()
            ->fetch('assoc');

        if ($row === false) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'passwordHash' => (string) $row['passwordHash'],
        ];
    }

    public function existsByUsername(string $username): bool
    {
        $row = $this->queryFactory->newSelect('User')
            ->select(['num' => 'COUNT(*)'])
            ->where(['Username' => $username])
            ->execute()
            ->fetch('assoc');

        return (int) ($row['num'] ?? 0) > 0;
    }

    public function createUser(string $username, string $passwordHash): int
    {
        return (int) $this->queryFactory
            ->newInsert('User', ['Username', 'PasswordHash'])
            ->values(['Username' => $username, 'PasswordHash' => $passwordHash])
            ->execute()
            ->lastInsertId();
    }
}
