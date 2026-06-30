<?php

declare(strict_types=1);

namespace App\Domain\Season\Service;

use App\Domain\Season\Data\SeasonStanding;
use App\Domain\Season\Repository\SeasonRepository;

final class SeasonStatisticsReader
{
    public function __construct(private SeasonRepository $seasonRepository)
    {
    }

    /**
     * Read the season standings for the given (or current) season.
     *
     * @return array<int, SeasonStanding>
     */
    public function getStatistics(?int $seasonId = null): array
    {
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }

        return $this->seasonRepository->findStandings($seasonId);
    }
}
