<?php

declare(strict_types=1);

namespace App\Test\Integration;

use Fig\Http\Message\StatusCodeInterface;

final class AuthEndpointsTest extends IntegrationTestCase
{
    public function testLoginWithValidCredentialsReturnsToken(): void
    {
        $response = $this->request('POST', '/api/login', [
            'username' => 'admin',
            'password' => self::ADMIN_PASSWORD,
        ]);
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['token']);
        self::assertSame('admin', $body['user']['username']);
        self::assertGreaterThan(0, $body['expiresIn']);
    }

    public function testLoginWithWrongPasswordReturns401(): void
    {
        $response = $this->request('POST', '/api/login', [
            'username' => 'admin',
            'password' => 'wrong-password',
        ]);
        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testLoginWithUnknownUserReturns401(): void
    {
        $response = $this->request('POST', '/api/login', [
            'username' => 'ghost',
            'password' => self::ADMIN_PASSWORD,
        ]);
        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testProtectedRouteWithoutTokenReturns401(): void
    {
        $response = $this->request('POST', '/api/seasons', ['period' => '2024 - 2025']);
        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testProtectedRouteWithInvalidTokenReturns401(): void
    {
        $response = $this->request('POST', '/api/seasons', ['period' => '2024 - 2025'], [], 'not-a-valid-token');
        self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testProtectedRouteWithValidTokenSucceeds(): void
    {
        $token = $this->jsonBody($this->request('POST', '/api/login', [
            'username' => 'admin',
            'password' => self::ADMIN_PASSWORD,
        ]))['token'];

        $response = $this->request('POST', '/api/seasons', ['period' => '2024 - 2025'], [], $token);
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }

    public function testPublicReadRemainsAccessibleWithoutToken(): void
    {
        $response = $this->request('GET', '/api/rankings');
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }
}
