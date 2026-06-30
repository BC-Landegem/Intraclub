<?php

declare(strict_types=1);

namespace App\Test\Support;

use App\Support\MatchCalculator;
use PHPUnit\Framework\TestCase;

final class MatchCalculatorTest extends TestCase
{
    public function testHomeSideWinsEverySet(): void
    {
        $stats = MatchCalculator::calculate(21, 10, 21, 10, 21, 10);

        // It is a rotating doubles format: player 1 is on the winning (home)
        // side in all three sets, each partner shares exactly one of them.
        self::assertSame(3, $stats['setsWonPlayer1']);
        self::assertSame(1, $stats['setsWonPlayer2']);
        self::assertSame(1, $stats['setsWonPlayer3']);
        self::assertSame(1, $stats['setsWonPlayer4']);
        self::assertSame(93, $stats['totalPoints']);
    }

    public function testScoresAboveTwentyOneAreTrimmed(): void
    {
        // A 25-23 set should not count more than 21 points for the average.
        $stats = MatchCalculator::calculate(25, 23, 21, 0, 21, 0);

        self::assertIsFloat($stats['averagePlayer1']);
        self::assertGreaterThan(0, $stats['averageLosing']);
    }
}
