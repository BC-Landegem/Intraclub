<?php

declare(strict_types=1);

namespace App\Test\Support;

use App\Support\Transformer;
use PHPUnit\Framework\TestCase;

final class TransformerTest extends TestCase
{
    public function testToPlayerStatisticsComputesLostValues(): void
    {
        $result = Transformer::toPlayerStatistics([
            'id' => '1',
            'firstName' => 'Jan',
            'name' => 'Jansen',
            'pointsWon' => '40',
            'pointsPlayed' => '100',
            'setsWon' => '5',
            'setsPlayed' => '9',
            'matchesPlayed' => '3',
            'roundsPresent' => '3',
        ]);

        self::assertSame(1, $result['id']);
        self::assertSame(60, $result['statistics']['points']['lost']);
        self::assertSame(4, $result['statistics']['sets']['lost']);
        self::assertSame(3, $result['statistics']['matches']['total']);
    }

    public function testToMatchBuildsNestedShape(): void
    {
        $row = [
            'id' => '7',
            'roundId' => '2',
            'roundNumber' => '2',
            'player1Id' => '1', 'player1FirstName' => 'A', 'player1Name' => 'A',
            'player2Id' => '2', 'player2FirstName' => 'B', 'player2Name' => 'B',
            'player3Id' => '3', 'player3FirstName' => 'C', 'player3Name' => 'C',
            'player4Id' => '4', 'player4FirstName' => 'D', 'player4Name' => 'D',
            'set1Home' => '21', 'set1Away' => '10',
            'set2Home' => '21', 'set2Away' => '12',
            'set3Home' => '0', 'set3Away' => '0',
        ];

        $match = Transformer::toMatch($row);

        self::assertSame(7, $match['id']);
        self::assertSame(1, $match['firstPlayer']['id']);
        self::assertSame(21, $match['firstSet']['home']);
        self::assertSame(2, $match['round']['number']);
    }
}
