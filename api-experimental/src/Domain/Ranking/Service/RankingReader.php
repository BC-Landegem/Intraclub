<?php

declare(strict_types=1);

namespace App\Domain\Ranking\Service;

use App\Domain\Ranking\Data\RankingEntry;
use App\Domain\Ranking\Data\Rankings;
use App\Domain\Ranking\Repository\RankingRepository;
use App\Domain\Round\Repository\RoundRepository;
use App\Domain\Season\Repository\SeasonRepository;
use DateTimeImmutable;

final class RankingReader
{
    public function __construct(
        private RankingRepository $rankingRepository,
        private RoundRepository $roundRepository,
        private SeasonRepository $seasonRepository,
    ) {
    }

    public function get(
        ?int $items = null,
        bool $showGeneral = false,
        bool $showWomen = false,
        bool $showVeterans = false,
        bool $showRecreants = false,
        ?int $seasonId = null,
        ?int $roundId = null,
    ): Rankings {
        $seasonId = $seasonId ?? $this->seasonRepository->getCurrentSeasonId();
        $round = $roundId === null
            ? $this->roundRepository->findLastCalculated($seasonId)
            : $this->roundRepository->findSummaryById($roundId);
        $ranking = $round === null
            ? $this->rankingRepository->findForNewSeason($seasonId)
            : $this->rankingRepository->findAfterRound($round->id);

        $previousRanking = [];
        if ($round !== null && $round->number > 1) {
            $previousRound = $this->roundRepository->findBySeasonAndNumber($seasonId, $round->number - 1);
            if ($previousRound !== null) {
                $previousRanking = $this->rankingRepository->findAfterRound($previousRound->id);
            }
        }

        return new Rankings(
            $seasonId,
            $showGeneral ? $this->buildRanking($ranking, $previousRanking, $this->filterNothing(...), $items) : null,
            $showWomen ? $this->buildRanking($ranking, $previousRanking, $this->filterWoman(...), $items) : null,
            $showVeterans ? $this->buildRanking($ranking, $previousRanking, $this->filterVeteran(...), $items) : null,
            $showRecreants ? $this->buildRanking($ranking, $previousRanking, $this->filterRecreant(...), $items) : null,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $ranking
     * @param array<int, array<string, mixed>> $previousRanking
     * @return array<int, RankingEntry>
     */
    private function buildRanking(array $ranking, array $previousRanking, callable $filter, ?int $items): array
    {
        $current = array_values(array_filter($ranking, $filter));
        $previous = $previousRanking ? array_values(array_filter($previousRanking, $filter)) : [];
        if (empty($items) || $items > count($current)) {
            $items = count($current);
        }
        $result = [];
        for ($i = 0; $i < $items; $i++) {
            $result[] = $this->mapToEntry($i, $current, $previous);
        }

        return $result;
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
        return ($player['gender'] ?? null) === 'Woman';
    }

    /**
     * @param array<string, mixed> $player
     */
    private function filterRecreant(array $player): bool
    {
        return (int) ($player['playsCompetition'] ?? 0) === 0;
    }

    /**
     * @param array<string, mixed> $player
     */
    private function filterVeteran(array $player): bool
    {
        $d = DateTimeImmutable::createFromFormat('Y-m-d', (string) $player['birthDate']);

        return $d && $d->diff(new DateTimeImmutable())->y >= 45;
    }

    /**
     * @param array<int, array<string, mixed>> $current
     * @param array<int, array<string, mixed>> $previous
     */
    private function mapToEntry(int $index, array $current, array $previous): RankingEntry
    {
        return new RankingEntry(
            (int) $current[$index]['id'],
            (string) $current[$index]['firstName'],
            (string) $current[$index]['name'],
            round((float) $current[$index]['average'], 2),
            $index + 1,
            $this->findDifference((int) $current[$index]['id'], $index + 1, $previous),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $previous
     */
    private function findDifference(int $playerId, int $currentRank, array $previous): int
    {
        if (!$previous) {
            return 0;
        }
        $foundIndex = array_search($playerId, array_map('intval', array_column($previous, 'id')), true);
        if ($foundIndex === false) {
            return 0;
        }

        return ((int) $foundIndex + 1) - $currentRank;
    }
}
