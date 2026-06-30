<?php

declare(strict_types=1);

namespace App\Domain\Auth\Service;

use App\Domain\Auth\Repository\LoginAttemptRepository;

/**
 * Simple persistent login throttle: blocks an identifier (e.g. client IP) after
 * too many failed attempts within a rolling time window.
 */
final class LoginThrottle
{
    public function __construct(
        private LoginAttemptRepository $repository,
        private int $maxAttempts = 5,
        private int $decaySeconds = 900,
    ) {
    }

    public function tooManyAttempts(string $identifier, int $now): bool
    {
        $since = date('Y-m-d H:i:s', $now - $this->decaySeconds);

        return $this->repository->countSince($identifier, $since) >= $this->maxAttempts;
    }

    public function recordFailure(string $identifier, int $now): void
    {
        $this->repository->record($identifier, date('Y-m-d H:i:s', $now));
    }

    public function clear(string $identifier): void
    {
        $this->repository->clear($identifier);
    }

    public function retryAfter(): int
    {
        return $this->decaySeconds;
    }
}
