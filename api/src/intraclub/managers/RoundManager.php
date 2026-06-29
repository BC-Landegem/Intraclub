<?php

declare(strict_types=1);

namespace intraclub\managers;

use intraclub\repositories\MatchRepository;
use intraclub\repositories\RoundRepository;
use intraclub\repositories\SeasonRepository;
use intraclub\common\Utilities;
use PDO;

class RoundManager
{
    protected RoundRepository $roundRepository;
    protected SeasonRepository $seasonRepository;
    protected MatchRepository $matchRepository;

    public function __construct(PDO $db)
    {
        $this->roundRepository = new RoundRepository($db);
        $this->matchRepository = new MatchRepository($db);
        $this->seasonRepository = new SeasonRepository($db);
    }

    /**
     * Creatie nieuw seizoen
     *
     * @param  string $date
     * @return void
     */
    public function create($date): void
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
    public function getByIdWithMatches($id): array
    {
        $roundInformation = $this->roundRepository->getWithMatches($id);
        $response = [];
        if (!empty($roundInformation)) {
            $response = [
                "id" => $roundInformation[0]["id"],
                "number" => $roundInformation[0]["number"],
                "averageAbsent" => $roundInformation[0]["averageAbsent"],
                "date" => $roundInformation[0]["date"],
            ];
            $response["matches"] = [];
            foreach ($roundInformation as $roundMatch) {
                $response["matches"][] = Utilities::mapToMatchObject($roundMatch);
            }
        }
        return $response;
    }

    /**
     * Haal alle speeldagen op
     *
     * @param  int $seasonId
     * @return ?array speeldagen
     */
    public function getAll($seasonId = null): ?array
    {
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }
        return $this->roundRepository->getAll($seasonId);
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
        $round = $this->roundRepository->getLast($seasonId);
        $matches = $this->matchRepository->getAllByRoundId($round["id"]);
        $round["matches"] = [];
        foreach ($matches as $match) {
            $round["matches"][] = Utilities::mapToMatchObject($match);
        }
        $round["availabilityData"] = $this->roundRepository->getAvailabilityData($round["id"]);

        return $round;
    }

    /**
     * Haal laatste BEREKENDE ronde op van seizoen
     *
     * @param  mixed $seasonId
     * @return ?array speeldag
     */
    public function getLastCalculated($seasonId = null): ?array
    {
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }
        $round = $this->roundRepository->getLastCalculated($seasonId);
        if (empty($round)) {
            return null;
        }
        $matches = $this->matchRepository->getAllByRoundId($round["id"]);
        $round["matches"] = [];
        foreach ($matches as $match) {
            $round["matches"][] = Utilities::mapToMatchObject($match);
        }
        $round["availabilityData"] = $this->roundRepository->getAvailabilityData($round["id"]);

        return $round;
    }
}
