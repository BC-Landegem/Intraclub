<?php

declare(strict_types=1);

namespace App\Domain\Player\Service;

use App\Domain\Match\Repository\MatchRepository;
use App\Domain\Player\Data\PlayerProfile;
use App\Domain\Player\Data\PlayerStatistics;
use App\Domain\Player\Repository\PlayerRepository;
use App\Domain\Ranking\Repository\RankingRepository;
use App\Domain\Season\Repository\SeasonRepository;

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
     */
    public function getPlayer(int $id, ?int $seasonId = null): ?PlayerProfile
    {
        if ($id <= 0) {
            return null;
        }
        if (empty($seasonId)) {
            $seasonId = $this->seasonRepository->getCurrentSeasonId();
        }

        $row = $this->repository->findPlayerWithSeason($id, $seasonId);
        if ($row === null) {
            return null;
        }

        return new PlayerProfile(
            (int) $row['id'],
            (string) $row['firstName'],
            (string) $row['name'],
            PlayerStatistics::fromRow($row),
            $this->matchRepository->findBySeasonAndPlayer($seasonId, $id),
            $this->rankingRepository->findHistory($id, $seasonId),
        );
    }
}
