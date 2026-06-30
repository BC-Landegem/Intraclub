<?php

declare(strict_types=1);

namespace App\Test\Data;

use App\Domain\Match\Data\MatchResult;
use App\Domain\Player\Data\PlayerSummary;
use App\Domain\Player\Data\StatLine;
use App\Domain\Player\Enum\Gender;
use App\Domain\Ranking\Data\RankingEntry;
use App\Domain\Ranking\Data\Rankings;
use PHPUnit\Framework\TestCase;

final class DtoSerializationTest extends TestCase
{
    public function testStatLineComputesLost(): void
    {
        $line = new StatLine(40, 100);
        self::assertSame(60, $line->lost());
        self::assertSame(['won' => 40, 'lost' => 60, 'total' => 100], $line->jsonSerialize());
    }

    public function testPlayerSummarySerializesGenderAsString(): void
    {
        $summary = PlayerSummary::fromRow([
            'id' => '1',
            'firstName' => 'Jan',
            'name' => 'Jansen',
            'gender' => 'Man',
            'doubleRanking' => '8',
            'playsCompetition' => '1',
            'member' => '1',
        ]);

        $json = json_decode((string) json_encode($summary), true);
        self::assertSame(1, $json['id']);
        self::assertSame('Man', $json['gender']);
        self::assertTrue($json['playsCompetition']);
        self::assertSame(Gender::Man, $summary->gender);
    }

    public function testMatchResultBuildsNestedShape(): void
    {
        $match = MatchResult::fromRow([
            'id' => '7', 'roundId' => '2', 'roundNumber' => '2',
            'player1Id' => '1', 'player1FirstName' => 'A', 'player1Name' => 'A',
            'player2Id' => '2', 'player2FirstName' => 'B', 'player2Name' => 'B',
            'player3Id' => '3', 'player3FirstName' => 'C', 'player3Name' => 'C',
            'player4Id' => '4', 'player4FirstName' => 'D', 'player4Name' => 'D',
            'set1Home' => '21', 'set1Away' => '10',
            'set2Home' => '21', 'set2Away' => '12',
            'set3Home' => '0', 'set3Away' => '0',
        ]);

        $json = json_decode((string) json_encode($match), true);
        self::assertSame(7, $json['id']);
        self::assertSame(2, $json['round']['number']);
        self::assertCount(2, $json['home']);
        self::assertCount(2, $json['away']);
        self::assertSame(1, $json['home'][0]['id']);
        self::assertSame(21, $json['sets'][0]['home']);
    }

    public function testRankingsOmitsNullCategories(): void
    {
        $entry = new RankingEntry(1, 'Jan', 'Jansen', 18.5, 1, 0);
        $rankings = new Rankings(3, general: [$entry]);

        $json = json_decode((string) json_encode($rankings), true);
        self::assertSame(3, $json['seasonId']);
        self::assertArrayHasKey('general', $json);
        self::assertArrayNotHasKey('women', $json);
        self::assertArrayNotHasKey('veterans', $json);
        self::assertSame(18.5, $json['general'][0]['average']);
    }
}
