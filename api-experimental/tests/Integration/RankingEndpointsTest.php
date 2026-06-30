<?php

declare(strict_types=1);

namespace App\Test\Integration;

use Fig\Http\Message\StatusCodeInterface;

final class RankingEndpointsTest extends IntegrationTestCase
{
    public function testAllRankings(): void
    {
        $response = $this->request('GET', '/api/rankings');
        self::assertSame(StatusCodeInterface::STATUS_OK, $response->getStatusCode());

        $rankings = $this->jsonBody($response);
        self::assertSame(1, $rankings['seasonId']);
        foreach (['general', 'women', 'veterans', 'recreants'] as $key) {
            self::assertArrayHasKey($key, $rankings);
        }
        self::assertCount(4, $rankings['general']); // four members
        self::assertSame(['id', 'firstName', 'name', 'average', 'rank', 'difference'], array_keys($rankings['general'][0]));
    }

    public function testGeneralRankingOnlyHasGeneralKey(): void
    {
        $rankings = $this->jsonBody($this->request('GET', '/api/rankings/general'));
        self::assertArrayHasKey('general', $rankings);
        self::assertArrayNotHasKey('women', $rankings);
    }

    public function testWomenRankingContainsOnlyWomen(): void
    {
        $rankings = $this->jsonBody($this->request('GET', '/api/rankings/women'));
        // Anna (1) and Dana (4) are the women members.
        $ids = array_column($rankings['women'], 'id');
        sort($ids);
        self::assertSame([1, 4], $ids);
    }

    public function testVeteransRankingContainsOnlyVeterans(): void
    {
        $rankings = $this->jsonBody($this->request('GET', '/api/rankings/veterans'));
        // Bart (2), born 1970, is the only veteran (>= 45).
        self::assertSame([2], array_column($rankings['veterans'], 'id'));
    }

    public function testRecreantsRankingContainsOnlyNonCompetitionPlayers(): void
    {
        $rankings = $this->jsonBody($this->request('GET', '/api/rankings/recreants'));
        // Carl (3) does not play competition.
        self::assertSame([3], array_column($rankings['recreants'], 'id'));
    }

    public function testRankingRespectsTopParameter(): void
    {
        $rankings = $this->jsonBody($this->request('GET', '/api/rankings/general', null, ['$top' => '2']));
        self::assertCount(2, $rankings['general']);
    }
}
