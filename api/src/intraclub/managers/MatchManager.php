<?php
namespace intraclub\managers;

use intraclub\repositories\SeasonRepository;
use intraclub\repositories\MatchRepository;
use intraclub\common\Utilities;

class MatchManager
{
    /**
     * Database connection
     *
     * @var \PDO
     */
    protected $db;
    /**
     * seasonRepository
     *
     * @var SeasonRepository
     */
    protected $seasonRepository;
    /**
     * matchRepository
     *
     * @var MatchRepository
     */
    protected $matchRepository;

    public function __construct($db)
    {
        $this->db = $db;
        $this->seasonRepository = new SeasonRepository($db);
        $this->matchRepository = new MatchRepository($db);
    }

    /**
     * Haal alle wedstrijden op van een ronde
     *
     * @param  int $roundId
     * @return array of matches
     */
    public function getAllByRoundId($roundId)
    {
        $matchesFromDB = $this->matchRepository->getAllByRoundId($roundId);
        $matches = array();
        for ($index = 0; $index < count($matchesFromDB); $index++) {
            $match = Utilities::mapToMatchObject($matchesFromDB[$index]);
            $matches[] = $match;
        }
        return $matches;
    }

    /**
     * Maak een nieuwe wedstrijd aan in een speeldag
     *
     * @param  int $roundId
     * @param  int $playerId1
     * @param  int $playerId2
     * @param  int $playerId3
     * @param  int $playerId4
     * @param  int $set1Home
     * @param  int $set1Away
     * @param  int $set2Home
     * @param  int $set2Away
     * @param  int $set3Home
     * @param  int $set3Away
     * @return void
     */
    public function create(
        $roundId,
        $playerId1,
        $playerId2,
        $playerId3,
        $playerId4,
        $set1Home,
        $set1Away,
        $set2Home,
        $set2Away,
        $set3Home,
        $set3Away
    ) {

        $this->matchRepository->create(
            $roundId,
            $playerId1,
            $playerId2,
            $playerId3,
            $playerId4,
            $set1Home,
            $set1Away,
            $set2Home,
            $set2Away,
            $set3Home,
            $set3Away
        );
    }

    /**
     * Update een wedstrijd
     *
     * @param  int $id
     * @param  int $playerId1
     * @param  int $playerId2
     * @param  int $playerId3
     * @param  int $playerId4
     * @param  int $set1Home
     * @param  int $set1Away
     * @param  int $set2Home
     * @param  int $set2Away
     * @param  int $set3Home
     * @param  int $set3Away
     * @return void
     */
    public function update(
        $id,
        $playerId1,
        $playerId2,
        $playerId3,
        $playerId4,
        $set1Home,
        $set1Away,
        $set2Home,
        $set2Away,
        $set3Home,
        $set3Away
    ) {

        return $this->matchRepository->update(
            $id,
            $playerId1,
            $playerId2,
            $playerId3,
            $playerId4,
            $set1Home,
            $set1Away,
            $set2Home,
            $set2Away,
            $set3Home,
            $set3Away
        );
    }
}