<?php

declare(strict_types=1);

namespace App\Domain\Round\Service;

use App\Domain\Match\Repository\MatchRepository;
use App\Domain\Round\Data\RoundDetail;
use App\Domain\Round\Data\RoundSummary;
use App\Domain\Round\Repository\RoundRepository;
use App\Domain\Season\Repository\SeasonRepository;

final class RoundReader
{
    public function __construct(
        private RoundRepository $roundRepository,
        private MatchRepository $matchRepository,
        private SeasonRepository $seasonRepository
    ) {
    }

    /**
     * @return array<int, RoundSummary>
     */
    public function getAll(?int $seasonId = null): array
    {
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }

        return $this->roundRepository->findBySeason($seasonId);
    }

    public function getByIdWithMatches(int $id): ?RoundDetail
    {
        $summary = $this->roundRepository->findSummaryById($id);
        if ($summary === null) {
            return null;
        }

        return RoundDetail::fromSummary(
            $summary,
            $this->matchRepository->findByRound($id),
            $this->roundRepository->findAvailability($id)
        );
    }

    public function getLast(?int $seasonId = null): ?RoundDetail
    {
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }

        $summary = $this->roundRepository->findLast($seasonId);
        if ($summary === null) {
            return null;
        }

        return RoundDetail::fromSummary(
            $summary,
            $this->matchRepository->findByRound($summary->id),
            $this->roundRepository->findAvailability($summary->id)
        );
    }

    public function getLastCalculated(?int $seasonId = null): ?RoundDetail
    {
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }

        $summary = $this->roundRepository->findLastCalculated($seasonId);
        if ($summary === null) {
            return null;
        }

        return RoundDetail::fromSummary(
            $summary,
            $this->matchRepository->findByRound($summary->id),
            $this->roundRepository->findAvailability($summary->id)
        );
    }
}
