<?php

declare(strict_types=1);

namespace App\Test\Integration;

use Fig\Http\Message\StatusCodeInterface;
use Nyholm\Psr7\Factory\Psr17Factory;

final class SecurityTest extends IntegrationTestCase
{
    public function testSecurityHeadersArePresent(): void
    {
        $response = $this->request('GET', '/api/rankings');

        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testCorsHeadersForAllowedOrigin(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/api/rankings')
            ->withHeader('Origin', 'https://app.test');

        $response = $this->app->handle($request);

        self::assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testNoCorsHeadersForDisallowedOrigin(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('GET', '/api/rankings')
            ->withHeader('Origin', 'https://evil.example');

        $response = $this->app->handle($request);

        self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testPreflightRequestIsAnswered(): void
    {
        $factory = new Psr17Factory();
        $request = $factory->createServerRequest('OPTIONS', '/api/players')
            ->withHeader('Origin', 'https://app.test');

        $response = $this->app->handle($request);

        self::assertSame(StatusCodeInterface::STATUS_NO_CONTENT, $response->getStatusCode());
        self::assertSame('https://app.test', $response->getHeaderLine('Access-Control-Allow-Origin'));
        self::assertStringContainsString('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testLoginIsRateLimited(): void
    {
        // The default throttle allows 5 attempts; the 6th is blocked.
        for ($i = 0; $i < 5; $i++) {
            $response = $this->request('POST', '/api/login', [
                'username' => 'admin',
                'password' => 'wrong-password',
            ]);
            self::assertSame(StatusCodeInterface::STATUS_UNAUTHORIZED, $response->getStatusCode());
        }

        $blocked = $this->request('POST', '/api/login', [
            'username' => 'admin',
            'password' => 'wrong-password',
        ]);
        self::assertSame(StatusCodeInterface::STATUS_TOO_MANY_REQUESTS, $blocked->getStatusCode());
        self::assertNotSame('', $blocked->getHeaderLine('Retry-After'));
    }
}
