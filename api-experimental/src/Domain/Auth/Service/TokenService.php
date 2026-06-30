<?php

declare(strict_types=1);

namespace App\Domain\Auth\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

/**
 * Issues and validates HS256 JSON Web Tokens.
 */
final class TokenService
{
    private const ALGORITHM = 'HS256';

    public function __construct(
        private string $secret,
        private int $expiresIn,
        private string $issuer,
    ) {
    }

    public function expiresIn(): int
    {
        return $this->expiresIn;
    }

    /**
     * Issue a signed token for a user.
     */
    public function issue(int $userId, string $username, int $issuedAt): string
    {
        $this->guardSecret();

        $payload = [
            'iss' => $this->issuer,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $this->expiresIn,
            'sub' => $userId,
            'username' => $username,
        ];

        return JWT::encode($payload, $this->secret, self::ALGORITHM);
    }

    /**
     * Validate a token and return its claims, or null when invalid/expired.
     *
     * @return array{sub: int, username: string}|null
     */
    public function validate(string $token): ?array
    {
        $this->guardSecret();

        try {
            $decoded = JWT::decode($token, new Key($this->secret, self::ALGORITHM));
        } catch (Throwable) {
            return null;
        }

        if (!isset($decoded->sub, $decoded->username)) {
            return null;
        }

        return [
            'sub' => (int) $decoded->sub,
            'username' => (string) $decoded->username,
        ];
    }

    private function guardSecret(): void
    {
        if ($this->secret === '') {
            throw new RuntimeException(
                'JWT secret is not configured. Set the JWT_SECRET environment variable.'
            );
        }
    }
}
