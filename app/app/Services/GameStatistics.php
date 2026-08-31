<?php

namespace App\Services;

use App\Models\Game;

/**
 * Statistieken van één game (3 sets, roterende teams onder 4 spelers).
 *
 * 1:1 port van intraclub\common\Utilities::calculateMatchStatistics uit de legacy-API.
 * Setwinnaars per set: set 1 home = (P1,P2), set 2 home = (P1,P3), set 3 home = (P1,P4).
 * Een gelijke of lege set telt als winst voor het "away"-team van die set (legacy-gedrag).
 */
readonly class GameStatistics
{
    /**
     * @param array<int, int> $setsWon setsgewonnen per spelerpositie (1..4)
     * @param array<int, float> $averages getrimd puntengemiddelde per spelerpositie (1..4)
     * @param array<int, int> $pointsWon ruwe punten per spelerpositie (1..4)
     */
    private function __construct(
        public array $setsWon,
        public array $averages,
        public array $pointsWon,
        public float $averageLosing,
        public int $totalPoints,
    ) {
    }

    public static function fromGame(Game $game): self
    {
        return self::fromScores(
            (int) $game->set1_home,
            (int) $game->set1_away,
            (int) $game->set2_home,
            (int) $game->set2_away,
            (int) $game->set3_home,
            (int) $game->set3_away,
        );
    }

    public static function fromScores(
        int $set1Home,
        int $set1Away,
        int $set2Home,
        int $set2Away,
        int $set3Home,
        int $set3Away,
    ): self {
        $setsWon = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
        $pointsLosingTeam = 0.0;

        if ($set1Home > $set1Away) {
            $setsWon[1]++;
            $setsWon[2]++;
            $pointsLosingTeam += self::trim($set1Away, $set1Home);
        } else {
            $setsWon[3]++;
            $setsWon[4]++;
            $pointsLosingTeam += self::trim($set1Home, $set1Away);
        }
        if ($set2Home > $set2Away) {
            $setsWon[1]++;
            $setsWon[3]++;
            $pointsLosingTeam += self::trim($set2Away, $set2Home);
        } else {
            $setsWon[2]++;
            $setsWon[4]++;
            $pointsLosingTeam += self::trim($set2Home, $set2Away);
        }
        if ($set3Home > $set3Away) {
            $setsWon[1]++;
            $setsWon[4]++;
            $pointsLosingTeam += self::trim($set3Away, $set3Home);
        } else {
            $setsWon[2]++;
            $setsWon[3]++;
            $pointsLosingTeam += self::trim($set3Home, $set3Away);
        }

        $pointsWon = [
            1 => $set1Home + $set2Home + $set3Home,
            2 => $set1Home + $set2Away + $set3Away,
            3 => $set1Away + $set2Home + $set3Away,
            4 => $set1Away + $set2Away + $set3Home,
        ];

        $averages = [
            1 => (self::trim($set1Home, $set1Away) + self::trim($set2Home, $set2Away) + self::trim($set3Home, $set3Away)) / 3,
            2 => (self::trim($set1Home, $set1Away) + self::trim($set2Away, $set2Home) + self::trim($set3Away, $set3Home)) / 3,
            3 => (self::trim($set1Away, $set1Home) + self::trim($set2Home, $set2Away) + self::trim($set3Away, $set3Home)) / 3,
            4 => (self::trim($set1Away, $set1Home) + self::trim($set2Away, $set2Home) + self::trim($set3Home, $set3Away)) / 3,
        ];

        return new self(
            setsWon: $setsWon,
            averages: $averages,
            pointsWon: $pointsWon,
            averageLosing: $pointsLosingTeam / 3,
            totalPoints: $set1Home + $set1Away + $set2Home + $set2Away + $set3Home + $set3Away,
        );
    }

    /**
     * Herschaal een setscore naar een 21-puntenschaal wanneer er voorbij 21 werd gespeeld
     * (verlengingen), zodat elke set even zwaar doorweegt in het gemiddelde.
     */
    private static function trim(int $score, int $opponentScore): float
    {
        return ($score > 21 || $opponentScore > 21)
            ? 21 / max($score, $opponentScore) * $score
            : (float) $score;
    }
}
