<?php

declare(strict_types=1);

namespace App\Domain\Season\Service;

use App\Domain\Player\Repository\PlayerRepository;
use App\Domain\Ranking\Service\RankingReader;
use App\Domain\Season\Repository\SeasonRepository;

final class SeasonCreator
{
    public function __construct(
        private SeasonRepository $seasonRepository,
        private PlayerRepository $playerRepository,
        private RankingReader $rankingReader,
        private SeasonValidator $validator
    ) {
    }

    /**
     * Create a new season and seed each player's season statistic with base
     * points derived from the reversed current general ranking.
     */
    public function createSeason(string $period): void
    {
        $this->validator->validateCreateSeason($period);

        $ranking = $this->rankingReader->get(null, true);
        $newSeasonId = $this->seasonRepository->insertSeason($period);
        $reversedRanking = array_reverse($ranking['general']);

        $basePoints = 19.000;
        foreach ($reversedRanking as $rankedPlayer) {
            $this->playerRepository->createSeasonStatistic($newSeasonId, (int) $rankedPlayer['id'], $basePoints);
            $basePoints += 0.0001;
        }
    }
}
