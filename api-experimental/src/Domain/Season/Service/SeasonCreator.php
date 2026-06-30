<?php

declare(strict_types=1);

namespace App\Domain\Season\Service;

use App\Domain\Player\Repository\PlayerRepository;
use App\Domain\Ranking\Data\Rankings;
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

        /** @var Rankings $rankings */
        $rankings = $this->rankingReader->get(null, true);
        $newSeasonId = $this->seasonRepository->insertSeason($period);
        $general = $rankings->general ?? [];
        $reversed = array_reverse($general);

        $basePoints = 19.000;
        foreach ($reversed as $entry) {
            $this->playerRepository->createSeasonStatistic($newSeasonId, $entry->id, $basePoints);
            $basePoints += 0.0001;
        }
    }
}
