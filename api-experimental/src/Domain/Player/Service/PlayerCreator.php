<?php

declare(strict_types=1);

namespace App\Domain\Player\Service;

use App\Domain\Player\Repository\PlayerRepository;
use App\Domain\Season\Repository\SeasonRepository;

final class PlayerCreator
{
    public function __construct(
        private PlayerRepository $repository,
        private SeasonRepository $seasonRepository,
        private PlayerValidator $validator
    ) {
    }

    /**
     * Create a new player together with empty statistics for the current season.
     *
     * @param array<string, mixed> $data
     */
    public function createPlayer(array $data): int
    {
        $this->validator->validate($data, true);

        $playerId = $this->repository->insertPlayer(
            (string) $data['firstName'],
            (string) $data['name'],
            (string) $data['gender'],
            (string) $data['birthDate'],
            (int) $data['doubleRanking'],
            (bool) $data['playsCompetition']
        );

        $seasonId = $this->seasonRepository->getCurrentSeasonId();
        $this->repository->createSeasonStatistic($seasonId, $playerId, (float) $data['basePoints']);

        return $playerId;
    }
}
