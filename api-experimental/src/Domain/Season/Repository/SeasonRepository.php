<?php

declare(strict_types=1);

namespace App\Domain\Season\Repository;

use App\Domain\Season\Data\SeasonStanding;
use App\Factory\QueryFactory;

/**
 * Season repository backed by the CakePHP query builder.
 */
final class SeasonRepository
{
    public function __construct(private QueryFactory $queryFactory)
    {
    }

    public function getCurrentSeasonId(): int
    {
        $row = $this->queryFactory->newSelect('Season')
            ->select(['id' => 'Id'])
            ->order(['Id' => 'DESC'])
            ->limit(1)
            ->execute()
            ->fetch('assoc');

        return (int) $row['id'];
    }

    public function exists(string $name): bool
    {
        $row = $this->queryFactory->newSelect('Season')
            ->select(['num' => 'COUNT(*)'])
            ->where(['Name' => $name])
            ->execute()
            ->fetch('assoc');

        return (int) ($row['num'] ?? 0) > 0;
    }

    /**
     * @return array<int, SeasonStanding>
     */
    public function findStandings(int $seasonId): array
    {
        $rows = $this->queryFactory->newSelectQuery()
            ->select([
                'id' => 'IPLAYER.Id',
                'firstName' => 'IPLAYER.FirstName',
                'name' => 'IPLAYER.Name',
                'setsPlayed' => 'ISPS.SetsPlayed',
                'setsWon' => 'ISPS.SetsWon',
                'pointsPlayed' => 'ISPS.PointsPlayed',
                'pointsWon' => 'ISPS.PointsWon',
                'matchesPlayed' => 'ISPS.MatchesPlayed',
                'roundsPresent' => 'ISPS.RoundsPresent',
            ])
            ->from(['IPLAYER' => 'Player'])
            ->innerJoin(['ISPS' => 'PlayerSeasonStatistic'], 'ISPS.PlayerId = IPLAYER.Id')
            ->where(['ISPS.SeasonId' => $seasonId, 'IPLAYER.Member' => 1])
            ->order([
                'ISPS.RoundsPresent' => 'DESC',
                'ISPS.SetsWon' => 'DESC',
                'ISPS.BasePoints' => 'DESC',
            ])
            ->execute()
            ->fetchAll('assoc') ?: [];

        return array_map(
            static fn (array $row): SeasonStanding => SeasonStanding::fromRow($row),
            $rows
        );
    }

    public function insertSeason(string $period): int
    {
        return (int) $this->queryFactory
            ->newInsert('Season', ['Name'])
            ->values(['Name' => $period])
            ->execute()
            ->lastInsertId();
    }
}
