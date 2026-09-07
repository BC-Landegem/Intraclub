<?php

namespace intraclub\managers;

use intraclub\common\Utilities;
use intraclub\repositories\SeasonRepository;
use intraclub\repositories\RoundRepository;
use intraclub\repositories\MatchRepository;
use intraclub\repositories\PlayerRepository;


class SeasonManager
{
    /**
     * Database connection
     *
     * @var \PDO
     */
    protected $db;
    /**
     * rankingManager
     *
     * @var RankingManager
     */
    protected $rankingManager;
    /**
     * seasonRepository
     *
     * @var SeasonRepository
     */
    protected $seasonRepository;
    /**
     * roundRepository
     *
     * @var RoundRepository
     */
    protected $roundRepository;
    /**
     * matchRepository
     *
     * @var MatchRepository
     */
    protected $matchRepository;
    /**
     * playerRepository
     *
     * @var PlayerRepository
     */
    protected $playerRepository;

    public function __construct($db)
    {
        $this->db = $db;
        $this->rankingManager = new RankingManager($this->db);
        $this->seasonRepository = new SeasonRepository($this->db);
        $this->roundRepository = new RoundRepository($this->db);
        $this->matchRepository = new MatchRepository($this->db);
        $this->playerRepository = new PlayerRepository($this->db);
    }

    /**
     * Haal seizoenstatistieken op
     *
     * @param  int $seasonId
     * @return array seizoensstatistieken
     */
    public function getStatistics($seasonId = null)
    {
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }
        $statisticsInfo = $this->seasonRepository->getStatistics($seasonId);
        $response = array();
        if (!empty($statisticsInfo)) {
            for ($index = 0; $index < count($statisticsInfo); $index++) {
                $playerStats = $statisticsInfo[$index];
                $playerStatistics = Utilities::mapToPlayerStatisticsObject($playerStats);
                $response[] = $playerStatistics;
            }
        }
        return $response;
    }
    /**
     * Creatie nieuw seizoen, inclusief lege seizoensstatistieken
     *
     * @param  string $period
     * @return void
     */
    public function create($period)
    {

        //1. Get current ranking
        $ranking = $this->rankingManager->get(null, true);

        //2. Insert new season
        $newSeasonId = $this->seasonRepository->create($period);

        //3. Insert playerPerSeason Record for every player & Based on ranking -> Add some points 
        $reversedRanking = array_reverse($ranking["general"]);
        $basePoints = 19.000;
        foreach ($reversedRanking as $rankedPlayer) {
            $this->playerRepository->createSeasonStatistic($newSeasonId, $rankedPlayer["id"], $basePoints);
            $basePoints += 0.0001;
        }
    }

    /**
     * Bereken tussenstand huidig seizoen
     *
     * @return void
     */
    public function calculateCurrentSeason()
    {
        $currentSeasonId = $this->seasonRepository->getCurrentSeasonId();
        $roundsOfCurrentSeason = $this->roundRepository->getAll($currentSeasonId);

        $averageLosersArray = array();
        $roundNumber = 1;

        /*
         * BEGIN BEPALEN GEMIDDELDE VERLIEZERS / SPEELDAG
         */
        foreach ($roundsOfCurrentSeason as $round) {
            $averageLosers = 0;
            $totalMatches = 0;

            $matches = $this->matchRepository->getAllByRoundId($round["id"]);
            foreach ($matches as $match) {
                $score_array = Utilities::calculateMatchStatistics(
                    $match["player1Id"],
                    $match["player2Id"],
                    $match["player3Id"],
                    $match["player4Id"],
                    $match["set1Home"],
                    $match["set1Away"],
                    $match["set2Home"],
                    $match["set2Away"],
                    $match["set3Home"],
                    $match["set3Away"]
                );

                $averageLosers += $score_array['averageLosing'];
                $totalMatches++;
            }

            $averageLosingCurrentRound = $averageLosers / $totalMatches;
            $this->roundRepository->updateAverageAbsent($round["id"], $averageLosingCurrentRound);
            $averageLosersArray[$roundNumber] = $averageLosingCurrentRound;
            $roundNumber++;
        }
        /*
         * EINDE BEPALEN VERLIEZERS
         */

        $lastRoundNumber = $roundNumber - 1;

        /*
         * Resultaat per speler bepalen
         */
        $allPlayers = $this->playerRepository->getAllWithSeasonInfo($currentSeasonId, true);

        foreach ($allPlayers as $player) {
            $resultArray = array();
            // basispunt als beginwaarde zetten
            $resultArray[0] = $player['basePoints'];
            $roundNumber = 1;

            $seasonStats = array(
                "setsPlayed" => 0,
                "setsWon" => 0,
                "roundsPresent" => 0,
                "matchesPlayed" => 0,
                "pointsPlayed" => 0,
                "pointsWon" => 0
            );

            /*
             * Overloop de wedstrijden van de speler
             */
            $matchesCurrentPlayer = $this->matchRepository->getAllBySeasonAndPlayerId($currentSeasonId, $player["id"]);
            foreach ($matchesCurrentPlayer as $matchCurrentPlayer) {
                while ($matchCurrentPlayer["roundNumber"] > $roundNumber) {
                    //Speler niet aanwezig op $roundNumber
                    //Geef hem gemiddelde verliezers van die speeldag!
                    $resultArray[$roundNumber] = $averageLosersArray[$roundNumber];
                    $roundNumber++;
                }
                // meerdere spelletjes gespeeld, OVERSLAAN
                if ($roundNumber > $matchCurrentPlayer["roundNumber"]) {
                    //Meermaals aanwezig op huidige speeldag
                } //We zitten goed!
                else if ($roundNumber == $matchCurrentPlayer["roundNumber"]) {

                    $matchStatistics = Utilities::calculateMatchStatistics(
                        $matchCurrentPlayer["player1Id"],
                        $matchCurrentPlayer["player2Id"],
                        $matchCurrentPlayer["player3Id"],
                        $matchCurrentPlayer["player4Id"],
                        $matchCurrentPlayer["set1Home"],
                        $matchCurrentPlayer["set1Away"],
                        $matchCurrentPlayer["set2Home"],
                        $matchCurrentPlayer["set2Away"],
                        $matchCurrentPlayer["set3Home"],
                        $matchCurrentPlayer["set3Away"]
                    );

                    $seasonStats["roundsPresent"]++;
                    $seasonStats["matchesPlayed"]++;
                    $seasonStats["pointsPlayed"] += $matchStatistics["totalPoints"];
                    $seasonStats["setsPlayed"] += 3;
                    switch ($player["id"]) {
                        case $matchCurrentPlayer["player1Id"]:
                            $resultArray[$roundNumber] = $matchStatistics["averagePlayer1"];
                            $seasonStats["setsWon"] += $matchStatistics["setsWonPlayer1"];
                            $seasonStats["pointsWon"] += $matchStatistics["pointsWonPlayer1"];
                            break;
                        case $matchCurrentPlayer["player2Id"]:
                            $resultArray[$roundNumber] = $matchStatistics["averagePlayer2"];
                            $seasonStats["setsWon"] += $matchStatistics["setsWonPlayer2"];
                            $seasonStats["pointsWon"] += $matchStatistics["pointsWonPlayer2"];
                            break;
                        case $matchCurrentPlayer["player3Id"]:
                            $resultArray[$roundNumber] = $matchStatistics["averagePlayer3"];
                            $seasonStats["setsWon"] += $matchStatistics["setsWonPlayer3"];
                            $seasonStats["pointsWon"] += $matchStatistics["pointsWonPlayer3"];
                            break;
                        case $matchCurrentPlayer["player4Id"]:
                            $resultArray[$roundNumber] = $matchStatistics["averagePlayer4"];
                            $seasonStats["setsWon"] += $matchStatistics["setsWonPlayer4"];
                            $seasonStats["pointsWon"] += $matchStatistics["pointsWonPlayer4"];
                            break;
                    }
                    //Volgende speeldag...
                    $roundNumber++;
                }
            }
            // laatste speeldagen niet aanwezig
            while ($roundNumber <= $lastRoundNumber) {
                $resultArray[$roundNumber] = $averageLosersArray[$roundNumber];
                $roundNumber++;
            }

            //We hebben nu $resultArray[speeldag] met gemiddelde voor elke speeldag van de speler
            //Geef speeldag  mee, samen met uitslag speeldag.
            //Hebben gemiddelde speeldag, MAAR MOETEN GEMIDDELDE TOT DIE SPEELDAG BEREKENEN! => done

            foreach ($roundsOfCurrentSeason as $round) {
                $sumOfAveragePerRound = 0;
                $totalRounds = 0;
                for ($j = 0; $j <= $round["number"]; $j++) {
                    $sumOfAveragePerRound += $resultArray[$j];
                    $totalRounds++;
                }
                //Tussenstand speeldag delen door aantal speeldagen +1
                //+1 = basispunten
                // +1 valt weg : laatste for-lus hierboven
                $averageRound = $sumOfAveragePerRound / ($totalRounds);
                $this->playerRepository->insertOrUpdateRoundStatistic(
                    $round["id"],
                    $player["id"],
                    $averageRound
                );
            }
            $this->playerRepository->updateSeasonStatistic(
                $currentSeasonId,
                $player["id"],
                $seasonStats["setsPlayed"],
                $seasonStats["setsWon"],
                $seasonStats["pointsPlayed"],
                $seasonStats["pointsWon"],
                $seasonStats["roundsPresent"],
                $seasonStats["matchesPlayed"]
            );
        }
    }
}
