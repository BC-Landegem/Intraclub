<?php

declare(strict_types=1);

namespace App\Test\Integration;

use Fig\Http\Message\StatusCodeInterface;

final class HomeEndpointTest extends IntegrationTestCase
{
    public function testHome(): void
    {
        $response = $this->request('GET', '/');
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        $response->getBody()->rewind();
        self::assertStringContainsString('Welcome', (string) $response->getBody());
    }
}
