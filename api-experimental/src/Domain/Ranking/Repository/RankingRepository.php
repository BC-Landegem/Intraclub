<?php

declare(strict_types=1);

namespace App\Domain\Ranking\Repository;

use App\Domain\Player\Data\RankingHistoryEntry;
use App\Factory\QueryFactory;

/**
 * Ranking repository.
 *
 * Uses the CakePHP query builder (via QueryFactory), including window functions,
 * to reproduce the original ranking SQL.
 */
final class RankingRepository
{
    public function __construct(private QueryFactory $queryFactory)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findForNewSeason(int $seasonId): array
    {
        $rows = $this->queryFactory->newSelectQuery()
            ->select([
                'rank' => $this->queryFactory->expr('ROW_NUMBER() OVER (ORDER BY ISPS.BasePoints DESC)'),
                'id' => 'IP.Id',
                'name' => 'IP.Name',
                'firstName' => 'IP.FirstName',
                'gender' => 'IP.Gender',
                'birthDate' => 'IP.BirthDate',
                'average' => 'ISPS.BasePoints',
                'doubleRanking' => 'IP.DoubleRanking',
                'playsCompetition' => 'IP.PlaysCompetition',
            ])
            ->from(['ISPS' => 'PlayerSeasonStatistic'])
            ->innerJoin(['IP' => 'Player'], 'IP.Id = ISPS.PlayerId')
            ->where(['ISPS.SeasonId' => $seasonId, 'IP.Member' => 1])
            ->order(['rank' => 'ASC'])
            ->execute()
            ->fetchAll('assoc');

        return $rows ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAfterRound(int $roundId): array
    {
        $rows = $this->queryFactory->newSelectQuery()
            ->select([
                'rank' => $this->queryFactory->expr('ROW_NUMBER() OVER (ORDER BY ISPS.Average DESC)'),
                'id' => 'IP.Id',
                'name' => 'IP.Name',
                'firstName' => 'IP.FirstName',
                'gender' => 'IP.Gender',
                'doubleRanking' => 'IP.DoubleRanking',
                'average' => 'ISPS.Average',
                'birthDate' => 'IP.BirthDate',
                'playsCompetition' => 'IP.PlaysCompetition',
            ])
            ->from(['ISPS' => 'PlayerRoundStatistic'])
            ->innerJoin(['IP' => 'Player'], 'IP.Id = ISPS.PlayerId')
            ->where(['ISPS.RoundId' => $roundId, 'IP.Member' => 1])
            ->order(['rank' => 'ASC'])
            ->execute()
            ->fetchAll('assoc');

        return $rows ?: [];
    }

    /**
     * @return array<int, RankingHistoryEntry>
     */
    public function findHistory(int $playerId, int $seasonId): array
    {
        $inner = $this->queryFactory->newSelectQuery()
            ->select([
                'rank' => $this->queryFactory->expr('ROW_NUMBER() OVER (PARTITION BY ISPS.RoundId ORDER BY ISPS.Average DESC)'),
                'id' => 'ISPS.PlayerId',
                'average' => 'ISPS.Average',
                'roundId' => 'ISPS.RoundId',
                'number' => 'ISPEEL.Number',
                'date' => 'ISPEEL.Date',
            ])
            ->from(['ISPS' => 'PlayerRoundStatistic'])
            ->innerJoin(['ISPEEL' => 'Round'], 'ISPEEL.Id = ISPS.RoundId')
            ->where(['ISPEEL.SeasonId' => $seasonId]);

        $rows = $this->queryFactory->newSelectQuery()
            ->select([
                'roundId' => 'FullRanking.roundId',
                'number' => 'FullRanking.number',
                'average' => 'FullRanking.average',
                'rank' => 'FullRanking.rank',
            ])
            ->from(['FullRanking' => $inner])
            ->where(['FullRanking.id' => $playerId])
            ->execute()
            ->fetchAll('assoc');

        return array_map(static fn (array $row) => RankingHistoryEntry::fromRow($row), $rows ?: []);
    }
}
