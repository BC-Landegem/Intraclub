<?php

declare(strict_types=1);

namespace App\Test\Integration;

use Fig\Http\Message\StatusCodeInterface;

final class MatchEndpointsTest extends IntegrationTestCase
{
    public function testListMatchesByRound(): void
    {
        $response = $this->request('GET', '/api/rounds/1/matches');
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        $matches = $this->jsonBody($response);
        self::assertCount(1, $matches);
        $match = $matches[0];
        self::assertSame(1, $match['id']);
        self::assertSame(['id', 'number'], array_keys($match['round']));
        self::assertCount(2, $match['home']);
        self::assertCount(2, $match['away']);
        self::assertCount(3, $match['sets']);
        self::assertSame(21, $match['sets'][0]['home']);
        self::assertSame(15, $match['sets'][0]['away']);
    }

    public function testCreateMatch(): void
    {
        $response = $this->request('POST', '/api/matches', [
            'roundId' => 1,
            'player1Id' => 1,
            'player2Id' => 2,
            'player3Id' => 3,
            'player4Id' => 4,
        ], [], $this->authToken());
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
        self::assertArrayHasKey('id', $this->jsonBody($response));

        // Round 1 now has two matches.
        $matches = $this->jsonBody($this->request('GET', '/api/rounds/1/matches'));
        self::assertCount(2, $matches);
    }

    public function testCreateMatchWithNonMemberReturns400(): void
    {
        $response = $this->request('POST', '/api/matches', [
            'roundId' => 1,
            'player1Id' => 5, // non-member
            'player2Id' => 2,
            'player3Id' => 3,
            'player4Id' => 4,
        ], [], $this->authToken());
        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
    }

    public function testUpdateMatchScores(): void
    {
        $response = $this->request('POST', '/api/matches/1', [
            'set1Home' => 21,
            'set1Away' => 19,
            'set2Home' => 18,
            'set2Away' => 21,
            'set3Home' => 21,
            'set3Away' => 12,
        ], [], $this->authToken());
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        $match = $this->jsonBody($this->request('GET', '/api/rounds/1/matches'))[0];
        self::assertSame(21, $match['sets'][2]['home']);
        self::assertSame(12, $match['sets'][2]['away']);
    }

    public function testUpdateMatchWithInvalidScoreReturns400(): void
    {
        $response = $this->request('POST', '/api/matches/1', [
            'set1Home' => 99, // out of range
            'set1Away' => 0,
            'set2Home' => 0,
            'set2Away' => 0,
            'set3Home' => 0,
            'set3Away' => 0,
        ], [], $this->authToken());
        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
    }
}
