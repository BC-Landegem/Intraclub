<?php

declare(strict_types=1);

namespace App\Domain\Round\Service;

use App\Domain\Round\Repository\RoundRepository;
use App\Domain\Season\Repository\SeasonRepository;

final class RoundCreator
{
    public function __construct(
        private RoundRepository $roundRepository,
        private SeasonRepository $seasonRepository,
        private RoundValidator $validator
    ) {
    }

    public function createRound(string $date): void
    {
        $this->validator->validateCreateRound($date);

        $currentSeasonId = $this->seasonRepository->getCurrentSeasonId();
        $roundNumber = 1;
        $round = $this->roundRepository->getLast($currentSeasonId);
        if (!empty($round)) {
            $roundNumber = (int) $round['number'] + 1;
        }

        $this->roundRepository->insertRound($currentSeasonId, $date, $roundNumber);
    }
}
