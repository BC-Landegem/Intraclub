<?php

declare(strict_types=1);

namespace App\Domain\Player\Service;

use App\Domain\Player\Repository\PlayerRepository;
use App\Domain\Round\Repository\RoundRepository;
use DomainException;

final class AttendanceUpdater
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private RoundRepository $roundRepository
    ) {
    }

    public function updateAttendance(int $playerId, int $roundId, bool $present, bool $drawnOut): void
    {
        $errors = [];
        if (!$this->playerRepository->exists($playerId)) {
            $errors[] = 'Speler met gegeven id bestaat niet!';
        }
        if (!$this->roundRepository->exists($roundId)) {
            $errors[] = 'Ronde met gegeven id bestaat niet!';
        }
        if ($errors) {
            throw new DomainException(implode(' ', $errors));
        }

        $this->playerRepository->insertOrUpdateAttendanceData($playerId, $roundId, $present, $drawnOut);
    }
}
