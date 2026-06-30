<?php

declare(strict_types=1);

namespace App\Domain\Auth\Repository;

use App\Factory\QueryFactory;

/**
 * Stores failed login attempts for throttling.
 */
final class LoginAttemptRepository
{
    public function __construct(private QueryFactory $queryFactory)
    {
    }

    /**
     * Number of attempts for an identifier at or after the given datetime.
     */
    public function countSince(string $identifier, string $since): int
    {
        $row = $this->queryFactory->newSelect('LoginAttempt')
            ->select(['num' => 'COUNT(*)'])
            ->where(['Identifier' => $identifier, 'AttemptedAt >=' => $since])
            ->execute()
            ->fetch('assoc');

        return (int) ($row['num'] ?? 0);
    }

    public function record(string $identifier, string $attemptedAt): void
    {
        $this->queryFactory
            ->newInsert('LoginAttempt', ['Identifier', 'AttemptedAt'])
            ->values(['Identifier' => $identifier, 'AttemptedAt' => $attemptedAt])
            ->execute();
    }

    /**
     * Remove all attempts for an identifier (e.g. after a successful login).
     */
    public function clear(string $identifier): void
    {
        $this->queryFactory->newDelete('LoginAttempt')
            ->where(['Identifier' => $identifier])
            ->execute();
    }
}
