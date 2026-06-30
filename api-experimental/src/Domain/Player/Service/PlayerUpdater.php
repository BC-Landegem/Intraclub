<?php

declare(strict_types=1);

namespace App\Domain\Player\Service;

use App\Domain\Player\Repository\PlayerRepository;
use DomainException;

final class PlayerUpdater
{
    public function __construct(
        private PlayerRepository $repository,
        private PlayerValidator $validator
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updatePlayer(int $id, array $data): void
    {
        if (!$this->repository->exists($id)) {
            throw new DomainException('Speler met gegeven id bestaat niet!');
        }

        $this->validator->validate($data, false);

        $this->repository->updatePlayer(
            $id,
            (string) $data['firstName'],
            (string) $data['name'],
            (string) $data['gender'],
            (string) $data['birthDate'],
            (int) $data['doubleRanking'],
            (bool) $data['playsCompetition']
        );
    }
}
