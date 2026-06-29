<?php

declare(strict_types=1);

namespace intraclub\managers;

use intraclub\common\Utilities;
use intraclub\repositories\MatchRepository;
use intraclub\repositories\PlayerRepository;
use intraclub\repositories\RankingRepository;
use intraclub\repositories\SeasonRepository;
use PDO;

class PlayerManager
{
    protected PlayerRepository $playerRepository;
    protected SeasonRepository $seasonRepository;
    protected RankingRepository $rankingRepository;
    protected MatchRepository $matchRepository;

    public function __construct(PDO $db)
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
    public function create($firstName, $name, $gender, $birthDate, $doubleRanking, $playsCompetition, $basePoints): void
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
    public function update($id, $firstName, $name, $gender, $isYouth, $isVeteran, $ranking): void
    {
        //Update speler
        $this->playerRepository->update($id, $firstName, $name, $gender, $isYouth, $isVeteran, $ranking);
    }

    /**
     * Haal alle spelers op
     *
     * @param  bool $onlyMembers alleen leden of alle spelers
     * @return array spelers
     */
    public function getAll($onlyMembers = true): array
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
    public function getByIdWithSeasonInfo($id, $seasonId): array
    {
        $response = [];
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

    public function updateAttendanceData($playerId, $roundId, $present, $drawnOut): void
    {
        $this->playerRepository->insertOrUpdateAttendanceData($playerId, $roundId, $present, $drawnOut);
    }

    /**
     * Map array naar rankingobjeccten
     *
     * @param  int $id
     * @param  int $seasonId
     * @return array(rankingObject)
     */
    private function getAndMapRankingHistory($id, $seasonId): array
    {
        $rankingHistory = $this->rankingRepository->getRankingHistoryByPlayerAndSeason($id, $seasonId);
        $mappedRankingHistory = [];
        foreach ($rankingHistory as $ranking) {
            $mappedRankingHistory[] = [
                "id" => $ranking["roundId"],
                "number" => intval($ranking["number"]),
                "average" => round($ranking["average"], 2),
                "rank" => intval($ranking["rank"]),
            ];
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
    private function getAndMapMatches($id, $seasonId): array
    {
        $matchesFromDB = $this->matchRepository->getAllBySeasonAndPlayerId($seasonId, $id);
        $matches = [];
        foreach ($matchesFromDB as $matchFromDB) {
            $matches[] = Utilities::mapToMatchObject($matchFromDB);
        }
        return $matches;
    }

    /**
     * Map array naar spelerstatistieken
     *
     * @param  int $id
     * @param  int $seasonId
     * @return array spelerstats
     */
    private function getAndMapPlayerInfoWithSeasonStats($id, $seasonId): array
    {
        $playerStats = $this->playerRepository->getByIdWithSeasonInfo($id, $seasonId);
        return Utilities::mapToPlayerStatisticsObject($playerStats);
    }
}
