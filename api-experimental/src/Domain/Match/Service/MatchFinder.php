<?php

declare(strict_types=1);

namespace App\Domain\Match\Service;

use App\Domain\Match\Data\MatchResult;
use App\Domain\Match\Repository\MatchRepository;

final class MatchFinder
{
    public function __construct(private MatchRepository $matchRepository)
    {
    }

    /**
     * @return array<int, MatchResult>
     */
    public function findByRound(int $roundId): array
    {
        return $this->matchRepository->findByRound($roundId);
    }
}
