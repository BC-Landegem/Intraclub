<?php

declare(strict_types=1);

namespace App\Test\Integration;

use Fig\Http\Message\StatusCodeInterface;

final class SeasonEndpointsTest extends IntegrationTestCase
{
    public function testLatestStatistics(): void
    {
        $response = $this->request('GET', '/api/seasons/latest/statistics');
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        $standings = $this->jsonBody($response);
        self::assertCount(4, $standings); // four members
        self::assertArrayHasKey('statistics', $standings[0]);
        self::assertArrayHasKey('points', $standings[0]['statistics']);
    }

    public function testCreateSeason(): void
    {
        $response = $this->request('POST', '/api/seasons', ['period' => '2024 - 2025'], [], $this->authToken());
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame('ok', $this->jsonBody($response)['status']);
    }

    public function testCreateDuplicateSeasonReturns400(): void
    {
        $response = $this->request('POST', '/api/seasons', ['period' => '2023 - 2024'], [], $this->authToken());
        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCreateSeasonWithoutPeriodReturns400(): void
    {
        $response = $this->request('POST', '/api/seasons', ['period' => ''], [], $this->authToken());
        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCalculateCurrentSeason(): void
    {
        $response = $this->request('POST', '/api/seasons/calculate', null, [], $this->authToken());
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame('ok', $this->jsonBody($response)['status']);

        // After recalculation the round's average-absent is updated and stats persist.
        $stats = $this->jsonBody($this->request('GET', '/api/seasons/latest/statistics'));
        self::assertNotEmpty($stats);
    }
}
