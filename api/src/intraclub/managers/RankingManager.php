<?php
namespace intraclub\managers;

use DateTime;
use intraclub\common\Utilities;
use intraclub\repositories\RankingRepository;
use intraclub\repositories\RoundRepository;
use intraclub\repositories\SeasonRepository;

class RankingManager
{
    /**
     * Ranking Repo
     *
     * @var RankingRepository
     */
    protected $rankingRepository;

    /**
     * Round Repo
     *
     * @var RoundRepository
     */
    protected $roundRepository;

    /**
     * seasonRepository
     *
     * @var SeasonRepository
     */
    protected $seasonRepository;

    public function __construct($db)
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
    public function get($items = null, $showGeneral = false, $showWomen = false, $showVeterans = false, $showRecreants = false, $seasonId = null, $roundId = null)
    {
        // Check if parameters are filled in
        // If not => return latest season, and latest calculated round
        $seasonId = $this->checkSeason($seasonId);
        $round = $this->checkRound($roundId, $seasonId);
        $ranking = null;
        // If round is still empty => no calculated round for current season
        if (empty($round)) {
            $ranking = $this->rankingRepository->getRankingForNewSeason($seasonId);
        } else {
            $ranking = $this->rankingRepository->getRankingAfterRound($round["id"]);
        }

        $previousRanking = array();

        if ($round["roundNumber"] > 1) {
            $previousRound = $this->roundRepository->getBySeasonAndNumber($seasonId, $round["roundNumber"] - 1);
            $previousRanking = $this->rankingRepository->getRankingAfterRound($previousRound["id"]);
        }
        //Build the rankings
        //Response
        $response = array("seasonId" => $seasonId);
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
        ;
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
    private function buildRanking($ranking, $previousRanking, $filterfunction, $items)
    {
        // Use array_values to reset keys
        $specificCurrentRanking = array_values(array_filter($ranking, array($this, $filterfunction)));
        $specificPreviousRanking = array();

        if (!empty($previousRanking)) {
            $specificPreviousRanking = array_values(array_filter($previousRanking, array($this, $filterfunction)));
        }
        $specificRanking = array();
        if (empty($items) || $items > $specificCurrentRanking) {
            $items = count($specificCurrentRanking);
        }
        for ($index = 0; $index < $items; $index++) {
            $player = $this->mapToRankingObject($index, $specificCurrentRanking, $specificPreviousRanking);
            $specificRanking[] = $player;
        }
        return $specificRanking;
    }

    /*
    Filter function to build rankings
     */
    private function filterNothing($player)
    {
        return true;
    }
    /**
     * Filter ranking op vrouwen
     *
     * @param  array $player
     * @return bool
     */
    private function filterWoman($player)
    {
        return $player["Gender"] == "Woman";
    }
    /**
     * Filter ranking op recreanten
     *
     * @param  array $player
     * @return bool
     */
    private function filterRecreant($player)
    {
        return $player["PlaysCompetition"] == 0;
    }
    /**
     * Filter ranking op veteranen
     * 45 jaar of ouder
     *
     * @param  array $player
     * @return bool
     */
    private function filterVeteran($player)
    {
        $birthDateString = $player["BirthDate"];
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
    private function mapToRankingObject($index, $currentRanking, $previousRanking)
    {
        return array(
            "id" => $currentRanking[$index]["Id"],
            "name" => $currentRanking[$index]["Name"],
            "firstName" => $currentRanking[$index]["FirstName"],
            "average" => round($currentRanking[$index]["Average"], 2),
            "rank" => $index + 1,
            "difference" => $this->findPreviousRanking($currentRanking[$index]["Id"], $index + 1, $previousRanking),
        );
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
    private function findPreviousRanking($playerId, $currentRank, $previousRanking)
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