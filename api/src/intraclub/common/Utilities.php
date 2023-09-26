<?php

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
    public static function mapToPlayerStatisticsObject($playerStats)
    {
        return array(
            "id" => $playerStats["id"],
            "firstName" => $playerStats["firstname"],
            "name" => $playerStats["name"],
            "statistics" => array(
                "points" => array(
                    "won" => intval($playerStats["pointsWon"]),
                    "lost" => $playerStats["pointsPlayed"] - $playerStats["pointsWon"],
                    "total" => intval($playerStats["pointsPlayed"])
                ),
                "sets" => array(
                    "won" => intval($playerStats["setsWon"]),
                    "lost" => $playerStats["setsPlayed"] - $playerStats["setsWon"],
                    "total" => intval($playerStats["setsPlayed"])
                ),
                "matches" => array(
                    "won" => intval($playerStats["matchesWon"]),
                    "lost" => $playerStats["matchesPlayed"] - $playerStats["matchesWon"],
                    "total" => intval($playerStats["matchesPlayed"])
                ),
                "rounds" => array(
                    "present" => intval($playerStats["roundsPresent"])
                )
            )
        );
    }
    /**
     * Map naar wedstrijd array
     *
     * @param  mixed $match
     * @return array wedstrijd
     */
    public static function mapToMatchObject($match)
    {
        return array(
            "home" => array(
                "firstPlayer" => array(
                    "id" => $match["home_firstPlayer_Id"],
                    "firstName" => $match["home_firstPlayer_firstName"],
                    "name" => $match["home_firstPlayer_name"]
                ),
                "secondPlayer" => array(
                    "id" => $match["home_secondPlayer_Id"],
                    "firstName" => $match["home_secondPlayer_firstName"],
                    "name" => $match["home_secondPlayer_name"]
                ),
            ),
            "away" => array(
                "firstPlayer" => array(
                    "id" => $match["away_firstPlayer_Id"],
                    "firstName" => $match["away_firstPlayer_firstName"],
                    "name" => $match["away_firstPlayer_name"]
                ),
                "secondPlayer" => array(
                    "id" => $match["away_secondPlayer_Id"],
                    "firstName" => $match["away_secondPlayer_firstName"],
                    "name" => $match["away_secondPlayer_name"]
                ),
            ),
            "firstSet" => array(
                "home" => intval($match["firstSet_home"]),
                "away" => intval($match["firstSet_away"])
            ),
            "secondSet" => array(
                "home" => intval($match["secondSet_home"]),
                "away" => intval($match["secondSet_away"])
            ),
            "thirdSet" => array(
                "home" => intval($match["thirdSet_home"]),
                "away" => intval($match["thirdSet_away"]),
                "played" => $match["thirdSet_home"] != "0" && $match["thirdSet_away"] != "0"
            ),
            "round" => array(
                "id" => intval($match["roundId"]),
                "number" => intval($match["roundNumber"])
            ),
        );
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
    ) {
        $setsWonPlayer1 = 0;
        $setsWonPlayer2 = 0;
        $setsWonPlayer3 = 0;
        $setsWonPlayer4 = 0;

        $pointsLosingTeam = 0;
        //Bepaal wie welke set wint
        if ($set1Home > $set2Away) {
            $setsWonPlayer1++;
            $setsWonPlayer2++;
            $pointsLosingTeam += Utilities::trimSets($set1Away, $set1Home);
        } else {
            $setsWonPlayer3++;
            $setsWonPlayer4++;
            $pointsLosingTeam += Utilities::trimSets($set1Home, $set1Away);
        }
        if ($set2Home > $set2Away) {
            $setsWonPlayer1++;
            $setsWonPlayer3++;
            $pointsLosingTeam += Utilities::trimSets($set2Away, $set2Home);
        } else {
            $setsWonPlayer2++;
            $setsWonPlayer4++;
            $pointsLosingTeam += Utilities::trimSets($set2Home, $set2Away);
        }
        if ($set3Home > $set3Away) {
            $setsWonPlayer1++;
            $setsWonPlayer4++;
            $pointsLosingTeam += Utilities::trimSets($set3Away, $set3Home);
        } else {
            $setsWonPlayer2++;
            $setsWonPlayer3++;
            $pointsLosingTeam += Utilities::trimSets($set3Home, $set3Away);
        }

        //Bereken totaal aantal punten
        $totalPlayer1 = Utilities::trimSets($set1Home, $set1Away)
            + Utilities::trimSets($set2Home, $set2Away) + Utilities::trimSets($set3Home, $set3Away);
        $totalPlayer2 = Utilities::trimSets($set1Home, $set1Away)
            + Utilities::trimSets($set2Away, $set2Home) + Utilities::trimSets($set3Away, $set3Home);
        $totalPlayer3 = Utilities::trimSets($set1Away, $set1Home)
            + Utilities::trimSets($set2Home, $set2Away) + Utilities::trimSets($set3Away, $set3Home);
        $totalPlayer4 = Utilities::trimSets($set1Away, $set1Home)
            + Utilities::trimSets($set2Away, $set2Home) + Utilities::trimSets($set3Home, $set3Away);

        return array(
            "setsWonPlayer1" => $setsWonPlayer1,
            "setsWonPlayer2" => $setsWonPlayer2,
            "setsWonPlayer3" => $setsWonPlayer3,
            "setsWonPlayer4" => $setsWonPlayer4,
            "averagePlayer1" => $totalPlayer1 / 3,
            "averagePlayer2" => $totalPlayer2 / 3,
            "averagePlayer3" => $totalPlayer3 / 3,
            "averagePlayer4" => $totalPlayer4 / 3,
            "averageLosing" => $pointsLosingTeam / 3
        );
    }

    /**
     * Controle of waarde een getal is
     *
     * @param  mixed $value
     * @return void
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
    public static function isDate($date, $format = 'Y-m-d')
    {
        $d = DateTime::createFromFormat($format, $date);
        // The Y ( 4 digits year ) returns TRUE for any integer 
        // with any number of digits so changing the comparison 
        // from == to === fixes the issue.
        return $d && $d->format($format) === $date;
    }
    public static function isDateInFuture($date, $format = 'Y-m-d')
    {
        $d = DateTime::createFromFormat($format, $date);
        return $d > new DateTime();
    }
}