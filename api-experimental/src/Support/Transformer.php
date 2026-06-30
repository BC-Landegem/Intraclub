<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Maps raw database rows to the JSON response shapes used by the API.
 *
 * The shapes are kept identical to the original (Slim 3) API so existing
 * frontend clients keep working after the migration.
 */
final class Transformer
{
    /**
     * Map player season statistics to the response shape.
     *
     * @param array<string, mixed> $playerStats
     *
     * @return array<string, mixed>
     */
    public static function toPlayerStatistics(array $playerStats): array
    {
        return [
            'id' => (int) $playerStats['id'],
            'firstName' => $playerStats['firstName'],
            'name' => $playerStats['name'],
            'statistics' => [
                'points' => [
                    'won' => (int) $playerStats['pointsWon'],
                    'lost' => (int) $playerStats['pointsPlayed'] - (int) $playerStats['pointsWon'],
                    'total' => (int) $playerStats['pointsPlayed'],
                ],
                'sets' => [
                    'won' => (int) $playerStats['setsWon'],
                    'lost' => (int) $playerStats['setsPlayed'] - (int) $playerStats['setsWon'],
                    'total' => (int) $playerStats['setsPlayed'],
                ],
                'matches' => [
                    'total' => (int) $playerStats['matchesPlayed'],
                ],
                'rounds' => [
                    'present' => (int) $playerStats['roundsPresent'],
                ],
            ],
        ];
    }

    /**
     * Map a match row (incl. all players) to the response shape.
     *
     * @param array<string, mixed> $match
     *
     * @return array<string, mixed>
     */
    public static function toMatch(array $match): array
    {
        return [
            'id' => (int) $match['id'],
            'firstPlayer' => [
                'id' => (int) $match['player1Id'],
                'firstName' => $match['player1FirstName'],
                'name' => $match['player1Name'],
            ],
            'secondPlayer' => [
                'id' => (int) $match['player2Id'],
                'firstName' => $match['player2FirstName'],
                'name' => $match['player2Name'],
            ],
            'thirdPlayer' => [
                'id' => (int) $match['player3Id'],
                'firstName' => $match['player3FirstName'],
                'name' => $match['player3Name'],
            ],
            'fourthPlayer' => [
                'id' => (int) $match['player4Id'],
                'firstName' => $match['player4FirstName'],
                'name' => $match['player4Name'],
            ],
            'firstSet' => [
                'home' => (int) $match['set1Home'],
                'away' => (int) $match['set1Away'],
            ],
            'secondSet' => [
                'home' => (int) $match['set2Home'],
                'away' => (int) $match['set2Away'],
            ],
            'thirdSet' => [
                'home' => (int) $match['set3Home'],
                'away' => (int) $match['set3Away'],
            ],
            'round' => [
                'id' => (int) $match['roundId'],
                'number' => (int) $match['roundNumber'],
            ],
        ];
    }
}
