<?php

declare(strict_types=1);

namespace App\Test\Integration;

use Fig\Http\Message\StatusCodeInterface;

final class RoundEndpointsTest extends IntegrationTestCase
{
    public function testListRounds(): void
    {
        $response = $this->request('GET', '/api/rounds');
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        $rounds = $this->jsonBody($response);
        self::assertCount(1, $rounds);
        self::assertSame(1, $rounds[0]['number']);
        self::assertSame('2023-09-15', $rounds[0]['date']);
        self::assertSame(1, $rounds[0]['matchCount']);
        self::assertTrue($rounds[0]['calculated']);
    }

    public function testListRoundsForExplicitSeason(): void
    {
        $response = $this->request('GET', '/api/rounds', null, ['seasonId' => '1']);
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertCount(1, $this->jsonBody($response));
    }

    public function testLatestRound(): void
    {
        $response = $this->request('GET', '/api/rounds/latest');
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        $round = $this->jsonBody($response);
        self::assertSame(1, $round['id']);
        self::assertNotEmpty($round['matches']);
        self::assertCount(4, $round['availability']);
    }

    public function testLatestCalculatedRound(): void
    {
        $response = $this->request('GET', '/api/rounds/latestCalculated');
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertSame(1, $this->jsonBody($response)['id']);
    }

    public function testRoundDetail(): void
    {
        $response = $this->request('GET', '/api/rounds/1');
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        $round = $this->jsonBody($response);
        self::assertSame(1, $round['id']);
        self::assertCount(1, $round['matches']);
        self::assertCount(4, $round['availability']);
    }

    public function testCreateRound(): void
    {
        $response = $this->request('POST', '/api/rounds', ['date' => '2023-10-01']);
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        // A second round now exists and gets the next number.
        $rounds = $this->jsonBody($this->request('GET', '/api/rounds'));
        self::assertCount(2, $rounds);
        self::assertSame(2, $rounds[1]['number']);
    }

    public function testCreateRoundWithInvalidDateReturns400(): void
    {
        $response = $this->request('POST', '/api/rounds', ['date' => 'nonsense']);
        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCreateDuplicateRoundReturns400(): void
    {
        $response = $this->request('POST', '/api/rounds', ['date' => '2023-09-15']);
        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
    }
}
