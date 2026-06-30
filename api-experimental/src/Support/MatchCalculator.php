<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Calculates match statistics (sets won, averages, points) from set scores.
 *
 * Ported verbatim from the original Slim 3 Utilities helper so the computed
 * standings remain identical.
 */
final class MatchCalculator
{
    /**
     * Calculate the statistics for a single match.
     *
     * @return array<string, int|float>
     */
    public static function calculate(
        int $set1Home,
        int $set1Away,
        int $set2Home,
        int $set2Away,
        int $set3Home,
        int $set3Away
    ): array {
        $setsWonPlayer1 = 0;
        $setsWonPlayer2 = 0;
        $setsWonPlayer3 = 0;
        $setsWonPlayer4 = 0;

        $pointsLosingTeam = 0;
        // Bepaal wie welke set wint
        if ($set1Home > $set1Away) {
            $setsWonPlayer1++;
            $setsWonPlayer2++;
            $pointsLosingTeam += self::trimSets($set1Away, $set1Home);
        } else {
            $setsWonPlayer3++;
            $setsWonPlayer4++;
            $pointsLosingTeam += self::trimSets($set1Home, $set1Away);
        }
        if ($set2Home > $set2Away) {
            $setsWonPlayer1++;
            $setsWonPlayer3++;
            $pointsLosingTeam += self::trimSets($set2Away, $set2Home);
        } else {
            $setsWonPlayer2++;
            $setsWonPlayer4++;
            $pointsLosingTeam += self::trimSets($set2Home, $set2Away);
        }
        if ($set3Home > $set3Away) {
            $setsWonPlayer1++;
            $setsWonPlayer4++;
            $pointsLosingTeam += self::trimSets($set3Away, $set3Home);
        } else {
            $setsWonPlayer2++;
            $setsWonPlayer3++;
            $pointsLosingTeam += self::trimSets($set3Home, $set3Away);
        }

        // Bereken totaal aantal punten
        $totalPlayer1 = $set1Home + $set2Home + $set3Home;
        $totalPlayer2 = $set1Home + $set2Away + $set3Away;
        $totalPlayer3 = $set1Away + $set2Home + $set3Away;
        $totalPlayer4 = $set1Away + $set2Away + $set3Home;

        $totalTrimmedPlayer1 = self::trimSets($set1Home, $set1Away)
            + self::trimSets($set2Home, $set2Away) + self::trimSets($set3Home, $set3Away);
        $totalTrimmedPlayer2 = self::trimSets($set1Home, $set1Away)
            + self::trimSets($set2Away, $set2Home) + self::trimSets($set3Away, $set3Home);
        $totalTrimmedPlayer3 = self::trimSets($set1Away, $set1Home)
            + self::trimSets($set2Home, $set2Away) + self::trimSets($set3Away, $set3Home);
        $totalTrimmedPlayer4 = self::trimSets($set1Away, $set1Home)
            + self::trimSets($set2Away, $set2Home) + self::trimSets($set3Home, $set3Away);

        $totalPoints = $set1Home + $set1Away + $set2Home + $set2Away + $set3Home + $set3Away;

        return [
            'setsWonPlayer1' => $setsWonPlayer1,
            'setsWonPlayer2' => $setsWonPlayer2,
            'setsWonPlayer3' => $setsWonPlayer3,
            'setsWonPlayer4' => $setsWonPlayer4,
            'averagePlayer1' => $totalTrimmedPlayer1 / 3,
            'averagePlayer2' => $totalTrimmedPlayer2 / 3,
            'averagePlayer3' => $totalTrimmedPlayer3 / 3,
            'averagePlayer4' => $totalTrimmedPlayer4 / 3,
            'averageLosing' => $pointsLosingTeam / 3,
            'pointsWonPlayer1' => $totalPlayer1,
            'pointsWonPlayer2' => $totalPlayer2,
            'pointsWonPlayer3' => $totalPlayer3,
            'pointsWonPlayer4' => $totalPlayer4,
            'totalPoints' => $totalPoints,
        ];
    }

    /**
     * Trim a set score so the winning score counts as a maximum of 21.
     */
    private static function trimSets(int $firstScore, int $secondScore): float
    {
        return ($firstScore > 21 || $secondScore > 21)
            ? 21 / max($firstScore, $secondScore) * $firstScore
            : $firstScore;
    }
}
