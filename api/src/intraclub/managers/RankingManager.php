<?php

declare(strict_types=1);

namespace intraclub\managers;

use DateTime;
use intraclub\repositories\RankingRepository;
use intraclub\repositories\RoundRepository;
use intraclub\repositories\SeasonRepository;
use PDO;

class RankingManager
{
    protected RankingRepository $rankingRepository;
    protected RoundRepository $roundRepository;
    protected SeasonRepository $seasonRepository;

    public function __construct(PDO $db)
    {
        $this->rankingRepository = new RankingRepository($db);
        $this->roundRepository = new RoundRepository($db);
        $this->seasonRepository = new SeasonRepository($db);
    }

    /**
     * Haal ranking op
     *
     * @param  int $items aantal items
     * @param  bool $showGeneral toon algemeen klassement
     * @param  bool $showWomen toon vrouwen klassement
     * @param  bool $showVeterans toon veteranen klassement
     * @param  bool $showRecreants toon recreanten klassement
     * @param  int $seasonId seizoen id
     * @param  int $roundId speeldag id
     * @return array ranking
     */
    public function get($items = null, $showGeneral = false, $showWomen = false, $showVeterans = false, $showRecreants = false, $seasonId = null, $roundId = null): array
    {
        // Check if parameters are filled in
        // If not => return latest season, and latest calculated round
        $seasonId = $this->checkSeason($seasonId);
        $round = $this->checkRound($roundId, $seasonId);
        // If round is still empty => no calculated round for current season
        if (empty($round)) {
            $ranking = $this->rankingRepository->getRankingForNewSeason($seasonId);
        } else {
            $ranking = $this->rankingRepository->getRankingAfterRound($round["id"]);
        }

        $previousRanking = [];

        if ($round["number"] > 1) {
            $previousRound = $this->roundRepository->getBySeasonAndNumber($seasonId, $round["number"] - 1);
            $previousRanking = $this->rankingRepository->getRankingAfterRound($previousRound["id"]);
        }
        //Build the rankings
        //Response
        $response = ["seasonId" => $seasonId];
        if ($showGeneral) {
            $response["general"] = $this->buildRanking($ranking, $previousRanking, "filterNothing", $items);
        }
        if ($showWomen) {
            $response["women"] = $this->buildRanking($ranking, $previousRanking, "filterWoman", $items);
        }
        if ($showRecreants) {
            $response["recreants"] = $this->buildRanking($ranking, $previousRanking, "filterRecreant", $items);
        }
        if ($showVeterans) {
            $response["veterans"] = $this->buildRanking($ranking, $previousRanking, "filterVeteran", $items);
        }
        return $response;
    }

    /**
     * Controle seizoen.
     *
     * @param  mixed $seasonId Indien leeg: huidig seizoen
     * @return int seasonId
     */
    private function checkSeason($seasonId)
    {
        if (empty($seasonId)) {
            return $this->seasonRepository->getCurrentSeasonId();
        }
        return $seasonId;
    }

    /**
     * Controle ronde
     *
     * @param  mixed $roundId indien leeg: laatst berekende ronde
     * @param  mixed $seasonId
     * @return array round
     */
    private function checkRound($roundId, $seasonId)
    {
        if (empty($roundId)) {
            return $this->roundRepository->getLastCalculated($seasonId);
        }
        return $this->roundRepository->getById($roundId);
    }

    /**
     * Generic Ranking builder function
     *
     * Accepts filterfunction to filter players on specific property
     *
     * @param  mixed $ranking
     * @param  mixed $previousRanking
     * @param  string $filterfunction
     * @param  int $items
     * @return array ranking
     */
    private function buildRanking($ranking, $previousRanking, $filterfunction, $items): array
    {
        // Use array_values to reset keys
        $specificCurrentRanking = array_values(array_filter($ranking, [$this, $filterfunction]));
        $specificPreviousRanking = [];

        if (!empty($previousRanking)) {
            $specificPreviousRanking = array_values(array_filter($previousRanking, [$this, $filterfunction]));
        }
        $specificRanking = [];
        if (empty($items) || $items > $specificCurrentRanking) {
            $items = count($specificCurrentRanking);
        }
        for ($index = 0; $index < $items; $index++) {
            $specificRanking[] = $this->mapToRankingObject($index, $specificCurrentRanking, $specificPreviousRanking);
        }
        return $specificRanking;
    }

    /*
    Filter function to build rankings
     */
    private function filterNothing($player): bool
    {
        return true;
    }

    /**
     * Filter ranking op vrouwen
     *
     * @param  array $player
     * @return bool
     */
    private function filterWoman($player): bool
    {
        return $player["gender"] == "Woman";
    }

    /**
     * Filter ranking op recreanten
     *
     * @param  array $player
     * @return bool
     */
    private function filterRecreant($player): bool
    {
        return $player["playsCompetition"] == 0;
    }

    /**
     * Filter ranking op veteranen
     * 45 jaar of ouder
     *
     * @param  array $player
     * @return bool
     */
    private function filterVeteran($player): bool
    {
        $birthDateString = $player["birthDate"];
        // convert to Date
        $birthDate = DateTime::createFromFormat("Y-m-d", $birthDateString);
        return $birthDate->diff(new DateTime())->y >= 45;
    }

    /**
     * Map to response object
     *
     * @param  int $index
     * @param  array $currentRanking
     * @param  array $previousRanking
     * @return array
     */
    private function mapToRankingObject($index, $currentRanking, $previousRanking): array
    {
        return [
            "id" => $currentRanking[$index]["id"],
            "name" => $currentRanking[$index]["name"],
            "firstName" => $currentRanking[$index]["firstName"],
            "average" => round($currentRanking[$index]["average"], 2),
            "rank" => $index + 1,
            "difference" => $this->findPreviousRanking($currentRanking[$index]["id"], $index + 1, $previousRanking),
        ];
    }

    /**
     * Find difference with previous ranking
     *
     * Returns 0 if no previous ranking available
     *
     * @param  int $playerId
     * @param  int $currentRank
     * @param  array $previousRanking
     * @return int difference
     */
    private function findPreviousRanking($playerId, $currentRank, $previousRanking): int
    {
        $difference = 0;
        if (!empty($previousRanking)) {
            $foundIndex = array_search($playerId, array_column($previousRanking, 'id'));
            $previousRank = $foundIndex + 1;
            $difference = $previousRank - $currentRank;
        }
        return $difference;
    }
}
