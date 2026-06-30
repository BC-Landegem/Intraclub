<?php

declare(strict_types=1);

namespace App\Domain\Auth\Service;

use App\Domain\User\Repository\UserRepository;

/**
 * Verifies credentials and issues access tokens.
 */
final class Authenticator
{
    public function __construct(
        private UserRepository $userRepository,
        private TokenService $tokenService,
    ) {
    }

    /**
     * Attempt a login. Returns the token payload on success, or null when the
     * credentials are invalid.
     *
     * @return array{token: string, expiresIn: int, user: array{id: int, username: string}}|null
     */
    public function attempt(string $username, string $password, int $issuedAt): ?array
    {
        $user = $this->userRepository->findByUsername($username);
        if ($user === null || !password_verify($password, $user['passwordHash'])) {
            return null;
        }

        return [
            'token' => $this->tokenService->issue($user['id'], $user['username'], $issuedAt),
            'expiresIn' => $this->tokenService->expiresIn(),
            'user' => ['id' => $user['id'], 'username' => $user['username']],
        ];
    }
}
