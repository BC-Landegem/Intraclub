<?php
namespace intraclub\managers;

use intraclub\repositories\RoundRepository;
use intraclub\repositories\SeasonRepository;
use intraclub\common\Utilities;

class RoundManager
{
    /**
     * Repo Layer
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
        $this->roundRepository = new RoundRepository($db);
        $this->seasonRepository = new SeasonRepository($db);
    }

    /**
     * Creatie nieuw seizoen
     *
     * @param  string $date
     * @return void
     */
    public function create($date)
    {
        $currentSeasonId = $this->seasonRepository->getCurrentSeasonId();
        $roundNumber = 1;
        $round = $this->roundRepository->getLast($currentSeasonId);
        if (!empty($round)) {
            $roundNumber = $round["Number"] + 1;
        }

        $this->roundRepository->create($currentSeasonId, $date, $roundNumber);
    }
    /**
     * Haal speeldag op met matchen
     *
     * @param  int $id
     * @return array wedstrijden
     */
    public function getByIdWithMatches($id)
    {
        $roundInformation = $this->roundRepository->getWithMatches($id);
        $response = array();
        if (!empty($roundInformation)) {
            $response = array(
                "id" => $roundInformation[0]["roundId"],
                "number" => $roundInformation[0]["roundNumber"],
                "averageAbsent" => $roundInformation[0]["averageAbsent"],
                "date" => $roundInformation[0]["date"]
            );
            $response["matches"] = array();
            for ($index = 0; $index < count($roundInformation); $index++) {
                $match = Utilities::mapToMatchObject($roundInformation[$index]);
                $response["matches"][] = $match;
            }
        }
        return $response;
    }

    /**
     * Haal alle speeldagen op
     *
     * @param  int $seasonId
     * @return array speeldagen
     */
    public function getAll($seasonId = null)
    {
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }
        return $this->roundRepository->getAll($seasonId);
    }

    /**
     * Haal speeldag op
     *
     * @param  int $id
     * @return array speeldag
     */
    public function getById($id)
    {
        return $this->roundRepository->getById($id);
    }

    /**
     * Haal speeldag op per nummer/seizoen
     *
     * @param  int $seasonId
     * @param  int $number
     * @return array speeldag
     */
    public function getBySeasonAndNumber($seasonId, $number)
    {
        return $this->roundRepository->getBySeasonAndNumber($seasonId, $number);
    }

    /**
     * Haal laatste ronde op van seizoen
     *
     * @param  mixed $seasonId
     * @return array speeldag
     */
    public function getLast($seasonId = null)
    {
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }
        return $this->roundRepository->getLast($seasonId);
    }

}