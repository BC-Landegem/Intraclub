<?php

declare(strict_types=1);

namespace App\Domain\Round\Service;

use App\Domain\Match\Repository\MatchRepository;
use App\Domain\Round\Repository\RoundRepository;
use App\Domain\Season\Repository\SeasonRepository;
use App\Support\Transformer;

final class RoundReader
{
    public function __construct(
        private RoundRepository $roundRepository,
        private MatchRepository $matchRepository,
        private SeasonRepository $seasonRepository
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAll(?int $seasonId = null): array
    {
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }

        return $this->roundRepository->getAllBySeason($seasonId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getByIdWithMatches(int $id): array
    {
        $roundInformation = $this->roundRepository->getWithMatches($id);
        $response = [];

        if (!empty($roundInformation)) {
            $response = [
                'id' => $roundInformation[0]['id'],
                'number' => $roundInformation[0]['number'],
                'averageAbsent' => $roundInformation[0]['averageAbsent'],
                'date' => $roundInformation[0]['date'],
            ];
            $response['matches'] = [];
            foreach ($roundInformation as $row) {
                $response['matches'][] = Transformer::toMatch($row);
            }
        }

        return $response;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLast(?int $seasonId = null): ?array
    {
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }

        $round = $this->roundRepository->getLast($seasonId);
        if ($round === null) {
            return null;
        }

        $round['matches'] = [];
        foreach ($this->matchRepository->getAllByRoundId((int) $round['id']) as $m) {
            $round['matches'][] = Transformer::toMatch($m);
        }
        $round['availabilityData'] = $this->roundRepository->getAvailabilityData((int) $round['id']);

        return $round;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLastCalculated(?int $seasonId = null): ?array
    {
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }

        $round = $this->roundRepository->getLastCalculated($seasonId);
        if ($round === null) {
            return null;
        }

        $round['matches'] = [];
        foreach ($this->matchRepository->getAllByRoundId((int) $round['id']) as $m) {
            $round['matches'][] = Transformer::toMatch($m);
        }
        $round['availabilityData'] = $this->roundRepository->getAvailabilityData((int) $round['id']);

        return $round;
    }
}
