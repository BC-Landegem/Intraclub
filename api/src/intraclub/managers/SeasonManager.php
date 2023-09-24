<?php

namespace intraclub\managers;

use intraclub\common\Utilities;
use intraclub\repositories\SeasonRepository;
use intraclub\repositories\StatisticsRepository;
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


    /**
     *Statistics Repository
     *
     * @var StatisticsRepository
     */
    protected $statisticsRepository;

    public function __construct($db)
    {
        $this->db = $db;
        $this->rankingManager = new RankingManager($this->db);
        $this->seasonRepository = new SeasonRepository($this->db);
        $this->statisticsRepository = new StatisticsRepository($this->db);
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
            $this->seasonRepository->createSeasonPlayerStatistic($newSeasonId, $rankedPlayer["id"], $basePoints);
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
                    $match["home_firstPlayer_Id"],
                    $match["home_secondPlayer_Id"],
                    $match["away_firstPlayer_Id"],
                    $match["away_secondPlayer_Id"],
                    $match["firstSet_home"],
                    $match["firstSet_away"],
                    $match["secondSet_home"],
                    $match["secondSet_away"],
                    $match["thirdSet_home"],
                    $match["thirdSet_away"]
                );

                $averageLosers += $score_array['averagePointsLosingTeam'];
                $totalMatches++;
            }

            $averageLosingCurrentRound = $averageLosers / $totalMatches;
            $this->roundRepository->update($round["id"], $averageLosingCurrentRound);
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
                "pointsPlayed" => 0,
                "pointsWon" => 0,
                "matchesPlayed" => 0,
                "matchesWon" => 0,
                "roundsPresent" => 0
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
                        $matchCurrentPlayer["home_firstPlayer_Id"],
                        $matchCurrentPlayer["home_secondPlayer_Id"],
                        $matchCurrentPlayer["away_firstPlayer_Id"],
                        $matchCurrentPlayer["away_secondPlayer_Id"],
                        $matchCurrentPlayer["firstSet_home"],
                        $matchCurrentPlayer["firstSet_away"],
                        $matchCurrentPlayer["secondSet_home"],
                        $matchCurrentPlayer["secondSet_away"],
                        $matchCurrentPlayer["thirdSet_home"],
                        $matchCurrentPlayer["thirdSet_away"]
                    );

                    $seasonStats["pointsPlayed"] += $matchStatistics["totalPoints"];
                    $seasonStats["setsPlayed"] += $matchStatistics["amountOfSets"];
                    $seasonStats["roundsPresent"]++;
                    $seasonStats["matchesPlayed"]++;

                    if (in_array($player["id"], $matchStatistics["winningTeamIds"])) {
                        // speler heeft gewonnen!
                        $resultArray[$roundNumber] = $matchStatistics["averagePointsWinningTeam"];
                        $seasonStats["pointsWon"] += $matchStatistics["totalPointsWinningTeam"];
                        $seasonStats["setsWon"] += 2;
                        $seasonStats["matchesWon"]++;
                    } else {
                        // speler heeft verloren, jammer
                        $resultArray[$roundNumber] = $matchStatistics["averagePointsLosingTeam"];
                        $seasonStats["pointsWon"] += $matchStatistics["totalPointsLosingTeam"];
                        $seasonStats["setsWon"] += $matchStatistics["amountOfSets"] - 2;
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
                for ($j = 0; $j <= $round["roundNumber"]; $j++) {
                    $sumOfAveragePerRound += $resultArray[$j];
                    $totalRounds++;
                }
                //Tussenstand speeldag delen door aantal speeldagen +1
                //+1 = basispunten
                // +1 valt weg : laatste for-lus hierboven
                $averageRound = $sumOfAveragePerRound / ($totalRounds);
                $this->statisticsRepository->insertOrUpdateRoundStatistics($round["id"], $player["id"], $averageRound);

            }
            $this->statisticsRepository->updateSeasonStatistics(
                $currentSeasonId,
                $player["id"],
                $seasonStats["setsPlayed"],
                $seasonStats["setsWon"],
                $seasonStats["pointsPlayed"],
                $seasonStats["pointsWon"],
                $seasonStats["matchesPlayed"],
                $seasonStats["matchesWon"],
                $seasonStats["roundsPresent"]
            );
        }


    }

}