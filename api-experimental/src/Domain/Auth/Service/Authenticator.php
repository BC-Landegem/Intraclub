<?php

declare(strict_types=1);

namespace App\Domain\Auth\Service;

use App\Domain\User\Repository\UserRepository;

/**
 * Verifies credentials and issues access tokens.
 */
final class Authenticator
{
    /**
     * A valid bcrypt hash of a random value, verified against when the user does
     * not exist so that response timing does not reveal whether a username is
     * valid (mitigates user enumeration).
     */
    private const DUMMY_HASH = '$2y$12$4incWYuzQGP7fNLBiaZUv.aQQKAHs.PWG5fNnsb/cCrUqQLGAlMUS';

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

        // Always run a hash verification so timing does not leak whether the
        // username exists.
        $passwordValid = password_verify($password, $user['passwordHash'] ?? self::DUMMY_HASH);
        if ($user === null || !$passwordValid) {
            return null;
        }

        return [
            'token' => $this->tokenService->issue($user['id'], $user['username'], $issuedAt),
            'expiresIn' => $this->tokenService->expiresIn(),
            'user' => ['id' => $user['id'], 'username' => $user['username']],
        ];
    }
}
