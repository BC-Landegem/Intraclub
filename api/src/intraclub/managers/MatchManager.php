<?php

declare(strict_types=1);

namespace intraclub\managers;

use intraclub\repositories\SeasonRepository;
use intraclub\repositories\MatchRepository;
use intraclub\common\Utilities;
use PDO;

class MatchManager
{
    protected SeasonRepository $seasonRepository;
    protected MatchRepository $matchRepository;

    public function __construct(protected PDO $db)
    {
        $this->seasonRepository = new SeasonRepository($db);
        $this->matchRepository = new MatchRepository($db);
    }

    /**
     * Haal alle wedstrijden op van een ronde
     *
     * @param  int $roundId
     * @return array of matches
     */
    public function getAllByRoundId($roundId): array
    {
        $matchesFromDB = $this->matchRepository->getAllByRoundId($roundId);
        $matches = [];
        foreach ($matchesFromDB as $matchFromDB) {
            $matches[] = Utilities::mapToMatchObject($matchFromDB);
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
     * @return int
     */
    public function create(
        $roundId,
        $playerId1,
        $playerId2,
        $playerId3,
        $playerId4
    ) {
        return $this->matchRepository->create(
            $roundId,
            $playerId1,
            $playerId2,
            $playerId3,
            $playerId4
        );
    }

    /**
     * Update een wedstrijd
     *
     * @param  int $id
     * @param  int $set1Home
     * @param  int $set1Away
     * @param  int $set2Home
     * @param  int $set2Away
     * @param  int $set3Home
     * @param  int $set3Away
     * @return bool
     */
    public function update(
        $id,
        $set1Home,
        $set1Away,
        $set2Home,
        $set2Away,
        $set3Home,
        $set3Away
    ): bool {
        return $this->matchRepository->update(
            $id,
            $set1Home,
            $set1Away,
            $set2Home,
            $set2Away,
            $set3Home,
            $set3Away
        );
    }
}
