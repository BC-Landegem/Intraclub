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

        $seasonId = $this->seasonRepository->getCurrentSeasonId();
        $last = $this->roundRepository->findLast($seasonId);
        $number = $last !== null ? $last->number + 1 : 1;

        $this->roundRepository->insertRound($seasonId, $date, $number);
    }
}
