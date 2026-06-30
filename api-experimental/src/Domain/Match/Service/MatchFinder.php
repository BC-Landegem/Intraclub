<?php

declare(strict_types=1);

namespace App\Domain\Match\Service;

use App\Domain\Match\Repository\MatchRepository;
use App\Support\Transformer;

final class MatchFinder
{
    public function __construct(private MatchRepository $matchRepository)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByRound(int $roundId): array
    {
        $matches = [];
        foreach ($this->matchRepository->getAllByRoundId($roundId) as $row) {
            $matches[] = Transformer::toMatch($row);
        }

        return $matches;
    }
}
