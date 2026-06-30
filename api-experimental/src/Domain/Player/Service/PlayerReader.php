<?php

declare(strict_types=1);

namespace App\Domain\Player\Service;

use App\Domain\Match\Repository\MatchRepository;
use App\Domain\Player\Repository\PlayerRepository;
use App\Domain\Ranking\Repository\RankingRepository;
use App\Domain\Season\Repository\SeasonRepository;
use App\Support\Transformer;

final class PlayerReader
{
    public function __construct(
        private PlayerRepository $repository,
        private SeasonRepository $seasonRepository,
        private MatchRepository $matchRepository,
        private RankingRepository $rankingRepository
    ) {
    }

    /**
     * Read a single player with season statistics, matches and ranking history.
     *
     * @return array<string, mixed>
     */
    public function getPlayer(int $id, ?int $seasonId = null): array
    {
        if ($id <= 0) {
            return [];
        }
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }

        $playerStats = $this->repository->getByIdWithSeasonInfo($id, $seasonId);
        if ($playerStats === null) {
            return [];
        }

        $response = Transformer::toPlayerStatistics($playerStats);
        $response['matches'] = $this->getMatches($id, $seasonId);
        $response['statistics']['rankingHistory'] = $this->getRankingHistory($id, $seasonId);

        return $response;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getMatches(int $id, int $seasonId): array
    {
        $matches = [];
        foreach ($this->matchRepository->getAllBySeasonAndPlayerId($seasonId, $id) as $matchRow) {
            $matches[] = Transformer::toMatch($matchRow);
        }

        return $matches;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRankingHistory(int $id, int $seasonId): array
    {
        $history = [];
        foreach ($this->rankingRepository->getRankingHistoryByPlayerAndSeason($id, $seasonId) as $ranking) {
            $history[] = [
                'id' => (int) $ranking['roundId'],
                'number' => (int) $ranking['number'],
                'average' => round((float) $ranking['average'], 2),
                'rank' => (int) $ranking['rank'],
            ];
        }

        return $history;
    }
}
