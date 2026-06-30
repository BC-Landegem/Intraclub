<?php

declare(strict_types=1);

namespace App\Domain\Match\Service;

use App\Domain\Match\Repository\MatchRepository;

final class MatchCreator
{
    public function __construct(
        private MatchRepository $matchRepository,
        private MatchValidator $validator
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createMatch(array $data): int
    {
        $roundId = (int) ($data['roundId'] ?? 0);
        $player1Id = (int) ($data['player1Id'] ?? 0);
        $player2Id = (int) ($data['player2Id'] ?? 0);
        $player3Id = (int) ($data['player3Id'] ?? 0);
        $player4Id = (int) ($data['player4Id'] ?? 0);

        $this->validator->validateCreateMatch($roundId, $player1Id, $player2Id, $player3Id, $player4Id);

        return $this->matchRepository->create($roundId, $player1Id, $player2Id, $player3Id, $player4Id);
    }
}
