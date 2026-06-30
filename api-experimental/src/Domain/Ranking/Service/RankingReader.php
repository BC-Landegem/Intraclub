<?php

declare(strict_types=1);

namespace App\Domain\Ranking\Service;

use App\Domain\Ranking\Repository\RankingRepository;
use App\Domain\Round\Repository\RoundRepository;
use App\Domain\Season\Repository\SeasonRepository;
use DateTime;

final class RankingReader
{
    public function __construct(
        private RankingRepository $rankingRepository,
        private RoundRepository $roundRepository,
        private SeasonRepository $seasonRepository
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(
        ?int $items = null,
        bool $showGeneral = false,
        bool $showWomen = false,
        bool $showVeterans = false,
        bool $showRecreants = false,
        ?int $seasonId = null,
        ?int $roundId = null
    ): array {
        $seasonId = $this->checkSeason($seasonId);
        $round = $this->checkRound($roundId, $seasonId);
        if (empty($round)) {
            $ranking = $this->rankingRepository->getRankingForNewSeason($seasonId);
        } else {
            $ranking = $this->rankingRepository->getRankingAfterRound((int) $round['id']);
        }
        $previousRanking = [];
        if (!empty($round) && $round['number'] > 1) {
            $previousRound = $this->roundRepository->getBySeasonAndNumber($seasonId, (int) $round['number'] - 1);
            if (!empty($previousRound)) {
                $previousRanking = $this->rankingRepository->getRankingAfterRound((int) $previousRound['id']);
            }
        }
        $response = ['seasonId' => $seasonId];
        if ($showGeneral) {
            $response['general'] = $this->buildRanking($ranking, $previousRanking, $this->filterNothing(...), $items);
        }
        if ($showWomen) {
            $response['women'] = $this->buildRanking($ranking, $previousRanking, $this->filterWoman(...), $items);
        }
        if ($showRecreants) {
            $response['recreants'] = $this->buildRanking($ranking, $previousRanking, $this->filterRecreant(...), $items);
        }
        if ($showVeterans) {
            $response['veterans'] = $this->buildRanking($ranking, $previousRanking, $this->filterVeteran(...), $items);
        }

        return $response;
    }

    private function checkSeason(?int $seasonId): int
    {
        if (empty($seasonId)) {
            return $this->seasonRepository->getCurrentSeasonId();
        }

        return $seasonId;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function checkRound(?int $roundId, int $seasonId): ?array
    {
        if (empty($roundId)) {
            return $this->roundRepository->getLastCalculated($seasonId);
        }

        return $this->roundRepository->getById($roundId);
    }

    /**
     * @param array<int, array<string, mixed>> $ranking
     * @param array<int, array<string, mixed>> $previousRanking
     * @return array<int, array<string, mixed>>
     */
    private function buildRanking(array $ranking, array $previousRanking, callable $filter, ?int $items): array
    {
        $specificCurrentRanking = array_values(array_filter($ranking, $filter));
        $specificPreviousRanking = [];
        if (!empty($previousRanking)) {
            $specificPreviousRanking = array_values(array_filter($previousRanking, $filter));
        }
        $specificRanking = [];
        if (empty($items) || $items > count($specificCurrentRanking)) {
            $items = count($specificCurrentRanking);
        }
        for ($index = 0; $index < $items; $index++) {
            $specificRanking[] = $this->mapToRankingObject($index, $specificCurrentRanking, $specificPreviousRanking);
        }

        return $specificRanking;
    }

    /**
     * @param array<string, mixed> $player
     */
    private function filterNothing(array $player): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $player
     */
    private function filterWoman(array $player): bool
    {
        return $player['gender'] == 'Woman';
    }

    /**
     * @param array<string, mixed> $player
     */
    private function filterRecreant(array $player): bool
    {
        return $player['playsCompetition'] == 0;
    }

    /**
     * @param array<string, mixed> $player
     */
    private function filterVeteran(array $player): bool
    {
        $birthDate = DateTime::createFromFormat('Y-m-d', (string) $player['birthDate']);

        return $birthDate && $birthDate->diff(new DateTime())->y >= 45;
    }

    /**
     * @param array<int, array<string, mixed>> $currentRanking
     * @param array<int, array<string, mixed>> $previousRanking
     * @return array<string, mixed>
     */
    private function mapToRankingObject(int $index, array $currentRanking, array $previousRanking): array
    {
        return [
            'id' => (int) $currentRanking[$index]['id'],
            'name' => $currentRanking[$index]['name'],
            'firstName' => $currentRanking[$index]['firstName'],
            'average' => round((float) $currentRanking[$index]['average'], 2),
            'rank' => $index + 1,
            'difference' => $this->findPreviousRanking((int) $currentRanking[$index]['id'], $index + 1, $previousRanking),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $previousRanking
     */
    private function findPreviousRanking(int $playerId, int $currentRank, array $previousRanking): int
    {
        $difference = 0;
        if (!empty($previousRanking)) {
            $foundIndex = array_search($playerId, array_map('intval', array_column($previousRanking, 'id')));
            $previousRank = (int) $foundIndex + 1;
            $difference = $previousRank - $currentRank;
        }

        return $difference;
    }
}
