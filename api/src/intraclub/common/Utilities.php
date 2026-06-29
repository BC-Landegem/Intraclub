<?php

declare(strict_types=1);

namespace intraclub\common;

use DateTime;

class Utilities
{
    /**
     * Map spelerstatistieken naar array
     *
     * @param  array $playerStats
     * @return array speler&statistiek object
     */
    public static function mapToPlayerStatisticsObject($playerStats): array
    {
        return [
            "id" => $playerStats["id"],
            "firstName" => $playerStats["firstName"],
            "name" => $playerStats["name"],
            "statistics" => [
                "points" => [
                    "won" => intval($playerStats["pointsWon"]),
                    "lost" => $playerStats["pointsPlayed"] - $playerStats["pointsWon"],
                    "total" => intval($playerStats["pointsPlayed"]),
                ],
                "sets" => [
                    "won" => intval($playerStats["setsWon"]),
                    "lost" => $playerStats["setsPlayed"] - $playerStats["setsWon"],
                    "total" => intval($playerStats["setsPlayed"]),
                ],
                "matches" => [
                    "total" => intval($playerStats["matchesPlayed"]),
                ],
                "rounds" => [
                    "present" => intval($playerStats["roundsPresent"]),
                ],
            ],
        ];
    }

    /**
     * Map naar wedstrijd array
     *
     * @param  mixed $match
     * @return array wedstrijd
     */
    public static function mapToMatchObject($match): array
    {
        return [
            "id" => $match["id"],
            "firstPlayer" => [
                "id" => $match["player1Id"],
                "firstName" => $match["player1FirstName"],
                "name" => $match["player1Name"],
            ],
            "secondPlayer" => [
                "id" => $match["player2Id"],
                "firstName" => $match["player2FirstName"],
                "name" => $match["player2Name"],
            ],
            "thirdPlayer" => [
                "id" => $match["player3Id"],
                "firstName" => $match["player3FirstName"],
                "name" => $match["player3Name"],
            ],
            "fourthPlayer" => [
                "id" => $match["player4Id"],
                "firstName" => $match["player4FirstName"],
                "name" => $match["player4Name"],
            ],
            "firstSet" => [
                "home" => intval($match["set1Home"]),
                "away" => intval($match["set1Away"]),
            ],
            "secondSet" => [
                "home" => intval($match["set2Home"]),
                "away" => intval($match["set2Away"]),
            ],
            "thirdSet" => [
                "home" => intval($match["set3Home"]),
                "away" => intval($match["set3Away"]),
            ],
            "round" => [
                "id" => intval($match["roundId"]),
                "number" => intval($match["roundNumber"]),
            ],
        ];
    }

    /**
     * Trim setscore, zodanig dat firstscore maximaal 21 is.
     *
     * @param  int $firstScore
     * @param  int $secondScore
     * @return int
     */
    private static function trimSets($firstScore, $secondScore)
    {
        return ($firstScore > 21 || $secondScore > 21) ? 21 / max($firstScore, $secondScore) * $firstScore : $firstScore;
    }

    /**
     * Bereken matchstatistieken (winnaar, sets, ...)
     *
     * @param  int $player1Id
     * @param  int $player2Id
     * @param  int $player3Id
     * @param  int $player4Id
     * @param  int $set1Home
     * @param  int $set1Away
     * @param  int $set2Home
     * @param  int $set2Away
     * @param  int $set3Home
     * @param  int $set3Away
     * @return array matchststatistieken
     */
    public static function calculateMatchStatistics(
        $player1Id,
        $player2Id,
        $player3Id,
        $player4Id,
        $set1Home,
        $set1Away,
        $set2Home,
        $set2Away,
        $set3Home,
        $set3Away
    ): array {
        $setsWonPlayer1 = 0;
        $setsWonPlayer2 = 0;
        $setsWonPlayer3 = 0;
        $setsWonPlayer4 = 0;

        $pointsLosingTeam = 0;
        //Bepaal wie welke set wint
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

        //Bereken totaal aantal punten
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
            "setsWonPlayer1" => $setsWonPlayer1,
            "setsWonPlayer2" => $setsWonPlayer2,
            "setsWonPlayer3" => $setsWonPlayer3,
            "setsWonPlayer4" => $setsWonPlayer4,
            "averagePlayer1" => $totalTrimmedPlayer1 / 3,
            "averagePlayer2" => $totalTrimmedPlayer2 / 3,
            "averagePlayer3" => $totalTrimmedPlayer3 / 3,
            "averagePlayer4" => $totalTrimmedPlayer4 / 3,
            "averageLosing" => $pointsLosingTeam / 3,
            "pointsWonPlayer1" => $totalPlayer1,
            "pointsWonPlayer2" => $totalPlayer2,
            "pointsWonPlayer3" => $totalPlayer3,
            "pointsWonPlayer4" => $totalPlayer4,
            "totalPoints" => $totalPoints,
        ];
    }

    /**
     * Controle of waarde een getal is
     *
     * @param  mixed $value
     * @return int|false
     */
    public static function isInt($value)
    {
        return filter_var($value, FILTER_VALIDATE_INT);
    }

    /**
     * Controle of waarde een datum is
     *
     * @param  string $date
     * @return boolean
     */
    public static function isDate($date, $format = 'Y-m-d'): bool
    {
        $d = DateTime::createFromFormat($format, $date);
        // The Y ( 4 digits year ) returns TRUE for any integer
        // with any number of digits so changing the comparison
        // from == to === fixes the issue.
        return $d && $d->format($format) === $date;
    }

    public static function isDateInFuture($date, $format = 'Y-m-d'): bool
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d > new DateTime();
    }
}
