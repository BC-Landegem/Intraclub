<?php

declare(strict_types=1);

namespace App\Test\Integration;

use Fig\Http\Message\StatusCodeInterface;

final class PlayerEndpointsTest extends IntegrationTestCase
{
    public function testListReturnsOnlyMembers(): void
    {
        $response = $this->request('GET', '/api/players');
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        $players = $this->jsonBody($response);
        self::assertCount(4, $players); // member 5 (Eve) is excluded
        self::assertSame(['id', 'firstName', 'name', 'gender', 'doubleRanking', 'playsCompetition', 'member'], array_keys($players[0]));
        foreach ($players as $player) {
            self::assertTrue($player['member']);
        }
    }

    public function testGetPlayerProfile(): void
    {
        $response = $this->request('GET', '/api/players/1');
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        $player = $this->jsonBody($response);
        self::assertSame(1, $player['id']);
        self::assertSame('Anna', $player['firstName']);
        self::assertArrayHasKey('statistics', $player);
        self::assertArrayHasKey('points', $player['statistics']);
        self::assertNotEmpty($player['matches']); // player 1 played match 1
        self::assertArrayHasKey('rankingHistory', $player);
        self::assertSame(1, $player['matches'][0]['home'][0]['id']);
    }

    public function testGetUnknownPlayerReturns404(): void
    {
        $response = $this->request('GET', '/api/players/9999');
        self::assertSame(StatusCodeInterface::STATUS_NOT_FOUND, $response->getStatusCode());
    }

    public function testCreatePlayer(): void
    {
        $response = $this->request('POST', '/api/players', [
            'firstName' => 'Frank',
            'name' => 'Fierens',
            'gender' => 'Man',
            'birthDate' => '1988-05-20',
            'doubleRanking' => 7,
            'playsCompetition' => true,
            'basePoints' => 18,
        ], [], $this->authToken());
        self::assertSame(StatusCodeInterface::STATUS_CREATED, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertArrayHasKey('id', $body);

        // The new player exists and has a season statistic for the current season.
        $follow = $this->request('GET', '/api/players/' . $body['id']);
        self::assertSame(StatusCodeInterface::STATUS_OK, $follow->getStatusCode());
        self::assertSame('Frank', $this->jsonBody($follow)['firstName']);
    }

    public function testCreatePlayerValidationError(): void
    {
        $response = $this->request('POST', '/api/players', [
            'firstName' => '',
            'name' => '',
            'gender' => 'Alien',
            'birthDate' => 'not-a-date',
            'doubleRanking' => 99,
            'playsCompetition' => 'yes',
            'basePoints' => 50,
        ], [], $this->authToken());
        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
        self::assertArrayHasKey('error', $this->jsonBody($response));
    }

    public function testUpdatePlayer(): void
    {
        $response = $this->request('POST', '/api/players/1', [
            'firstName' => 'Annabel',
            'name' => 'Albers',
            'gender' => 'Woman',
            'birthDate' => '1990-01-01',
            'doubleRanking' => 4,
            'playsCompetition' => true,
        ], [], $this->authToken());
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        $updated = $this->jsonBody($this->request('GET', '/api/players/1'));
        self::assertSame('Annabel', $updated['firstName']);
    }

    public function testUpdateUnknownPlayerReturns400(): void
    {
        $response = $this->request('POST', '/api/players/9999', [
            'firstName' => 'X',
            'name' => 'Y',
            'gender' => 'Man',
            'birthDate' => '1990-01-01',
            'doubleRanking' => 4,
            'playsCompetition' => true,
        ], [], $this->authToken());
        self::assertSame(StatusCodeInterface::STATUS_BAD_REQUEST, $response->getStatusCode());
    }

    public function testUpdateAttendance(): void
    {
        $response = $this->request('POST', '/api/rounds/1/players/1', [
            'present' => true,
            'drawnOut' => false,
        ], [], $this->authToken());
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());
    }
}
