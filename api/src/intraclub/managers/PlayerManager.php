<?php

namespace intraclub\managers;

use intraclub\common\Utilities;
use intraclub\repositories\MatchRepository;
use intraclub\repositories\PlayerRepository;
use intraclub\repositories\RankingRepository;
use intraclub\repositories\SeasonRepository;

class PlayerManager
{
    /**
     * Repo Layer
     *
     * @var PlayerRepository
     */
    protected $playerRepository;
    /**
     * seasonRepository
     *
     * @var SeasonRepository
     */
    protected $seasonRepository;
    /**
     * rankingRepository
     *
     * @var RankingRepository
     */
    protected $rankingRepository;
    /**
     * matchRepository
     *
     * @var MatchRepository
     */
    protected $matchRepository;

    public function __construct($db)
    {
        $this->playerRepository = new PlayerRepository($db);
        $this->seasonRepository = new SeasonRepository($db);
        $this->rankingRepository = new RankingRepository($db);
        $this->matchRepository = new MatchRepository($db);
    }
    /**
     * Toevoegen nieuwe speler + lege seizoensstats
     *
     * @param  string $firstName
     * @param  string $name
     * @param  string $gender
     * @param  string $birthDate
     * @param  int $doubleRanking
     * @param  bool $playsCompetition
     * @param  int $basePoints
     * @return void
     */
    public function create($firstName, $name, $gender, $birthDate, $doubleRanking, $playsCompetition, $basePoints)
    {
        //Aanmaak speler
        $playerId = $this->playerRepository->create($firstName, $name, $gender, $birthDate, $doubleRanking, $playsCompetition);
        //Aanmaak statistieken
        $seasonId = $this->seasonRepository->getCurrentSeasonId();
        $this->playerRepository->createSeasonStatistic($seasonId, $playerId, $basePoints);
    }

    /**
     * Update een bestaande speler
     *
     * @param  int $id
     * @param  string $firstName
     * @param  string $name
     * @param  string $gender
     * @param  bool $isYouth
     * @param  bool $isVeteran
     * @param  string $ranking
     * @return void
     */
    public function update($id, $firstName, $name, $gender, $isYouth, $isVeteran, $ranking)
    {
        //Update speler
        $playerId = $this->playerRepository->update($id, $firstName, $name, $gender, $isYouth, $isVeteran, $ranking);
    }

    /**
     * Haal alle spelers op
     *
     * @param  bool $onlyMembers alleen leden of alle spelers
     * @return array spelers
     */
    public function getAll($onlyMembers = true)
    {
        return $this->playerRepository->getAll($onlyMembers);
    }

    /**
     * Haal speler op met seizoensstatistieken
     *
     * @param  int $id
     * @param  int $seasonId
     * @return array speler met seizoensinfo
     */
    public function getByIdWithSeasonInfo($id, $seasonId)
    {
        $response = array();
        if (empty($id)) {
            return $response;
        }
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }
        //GetById + base statistics
        $response = $this->getAndMapPlayerInfoWithSeasonStats($id, $seasonId);
        //GetMatches
        $response["matches"] = $this->getAndMapMatches($id, $seasonId);
        //GetRankingHistory
        $response["statistics"]["rankingHistory"] = $this->getAndMapRankingHistory($id, $seasonId);
        return $response;
    }

    /**
     * Map array naar rankingobjeccten
     *
     * @param  int $id
     * @param  int $seasonId
     * @return array(rankingObject)
     */
    private function getAndMapRankingHistory($id, $seasonId)
    {
        $rankingHistory = $this->rankingRepository->getRankingHistoryByPlayerAndSeason($id, $seasonId);
        $mappedRankingHistory = array();
        if (!empty($rankingHistory)) {
            for ($index = 0; $index < count($rankingHistory); $index++) {
                $rankingObject = array(
                    "id" => $rankingHistory[$index]["speeldag_id"],
                    "number" => intval($rankingHistory[$index]["speeldagnummer"]),
                    "average" => round($rankingHistory[$index]["average"], 2),
                    "rank" => intval($rankingHistory[$index]["rank"])
                );
                $mappedRankingHistory[] = $rankingObject;
            }
        }
        return $mappedRankingHistory;
    }

    /**
     * Map array naar wedstrijden
     *
     * @param  int $id
     * @param  int $seasonId
     * @return array(matchObject)
     */
    private function getAndMapMatches($id, $seasonId)
    {
        $matchesFromDB = $this->matchRepository->getAllBySeasonAndPlayerId($seasonId, $id);
        $matches = array();
        if (!empty($matchesFromDB)) {
            for ($index = 0; $index < count($matchesFromDB); $index++) {
                $match = Utilities::mapToMatchObject($matchesFromDB[$index]);
                $matches[] = $match;
            }
        }
        return $matches;
    }
    /**
     * Map array naar spelerstatistieken
     *
     * @param  int $id
     * @param  int $seasonId
     * @return object spelerstats
     */
    private function getAndMapPlayerInfoWithSeasonStats($id, $seasonId)
    {
        $playerStats = $this->playerRepository->getByIdWithSeasonInfo($id, $seasonId);
        return Utilities::mapToPlayerStatisticsObject($playerStats);
    }
}