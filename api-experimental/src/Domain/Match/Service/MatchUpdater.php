<?php

declare(strict_types=1);

namespace App\Domain\Match\Service;

use App\Domain\Match\Repository\MatchRepository;

final class MatchUpdater
{
    public function __construct(
        private MatchRepository $matchRepository,
        private MatchValidator $validator
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateMatch(int $id, array $data): void
    {
        $set1Home = (int) ($data['set1Home'] ?? 0);
        $set1Away = (int) ($data['set1Away'] ?? 0);
        $set2Home = (int) ($data['set2Home'] ?? 0);
        $set2Away = (int) ($data['set2Away'] ?? 0);
        $set3Home = (int) ($data['set3Home'] ?? 0);
        $set3Away = (int) ($data['set3Away'] ?? 0);

        $this->validator->validateUpdateMatch($id, $set1Home, $set1Away, $set2Home, $set2Away, $set3Home, $set3Away);

        $this->matchRepository->update($id, $set1Home, $set1Away, $set2Home, $set2Away, $set3Home, $set3Away);
    }
}
