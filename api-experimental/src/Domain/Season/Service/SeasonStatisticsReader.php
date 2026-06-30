<?php

declare(strict_types=1);

namespace App\Domain\Season\Service;

use App\Domain\Season\Repository\SeasonRepository;
use App\Support\Transformer;

final class SeasonStatisticsReader
{
    public function __construct(private SeasonRepository $seasonRepository)
    {
    }

    /**
     * Read the season statistics for the given (or current) season.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStatistics(?int $seasonId = null): array
    {
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }

        $response = [];
        foreach ($this->seasonRepository->getStatistics($seasonId) as $row) {
            $response[] = Transformer::toPlayerStatistics($row);
        }

        return $response;
    }
}
