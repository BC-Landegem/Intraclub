<?php

declare(strict_types=1);

namespace App\Domain\Round\Repository;

use App\Domain\Round\Data\AvailabilityEntry;
use App\Domain\Round\Data\RoundSummary;
use App\Factory\QueryFactory;
use Cake\Database\Query\SelectQuery;

/**
 * Round (speeldag) repository (CakePHP query builder).
 */
final class RoundRepository
{
    public function __construct(private QueryFactory $queryFactory)
    {
    }

    /**
     * @return array<int, RoundSummary>
     */
    public function findBySeason(int $seasonId): array
    {
        $rows = $this->summaryQuery()
            ->where(['RND.SeasonId' => $seasonId])
            ->order(['RND.Id' => 'ASC'])
            ->execute()
            ->fetchAll('assoc');

        return array_map(static fn (array $row): RoundSummary => RoundSummary::fromRow($row), $rows ?: []);
    }

    public function findSummaryById(int $id): ?RoundSummary
    {
        $row = $this->summaryQuery()
            ->where(['RND.Id' => $id])
            ->execute()
            ->fetch('assoc');

        return $row === false ? null : RoundSummary::fromRow($row);
    }

    public function findBySeasonAndNumber(int $seasonId, int $number): ?RoundSummary
    {
        $row = $this->summaryQuery()
            ->where(['RND.SeasonId' => $seasonId, 'RND.Number' => $number])
            ->execute()
            ->fetch('assoc');

        return $row === false ? null : RoundSummary::fromRow($row);
    }

    public function findLast(int $seasonId): ?RoundSummary
    {
        $row = $this->summaryQuery()
            ->where(['RND.SeasonId' => $seasonId])
            ->order(['RND.Number' => 'DESC'])
            ->limit(1)
            ->execute()
            ->fetch('assoc');

        return $row === false ? null : RoundSummary::fromRow($row);
    }

    public function findLastCalculated(int $seasonId): ?RoundSummary
    {
        $row = $this->summaryQuery()
            ->where(['RND.SeasonId' => $seasonId, 'RND.Calculated' => 1])
            ->order(['RND.Number' => 'DESC'])
            ->limit(1)
            ->execute()
            ->fetch('assoc');

        return $row === false ? null : RoundSummary::fromRow($row);
    }

    /**
     * @return array<int, AvailabilityEntry>
     */
    public function findAvailability(int $roundId): array
    {
        $rows = $this->queryFactory->newSelect('PlayerRoundStatistic')
            ->select([
                'playerId' => 'PlayerId',
                'present' => 'Present',
                'drawnOut' => 'DrawnOut',
                'average' => 'Average',
            ])
            ->where(['RoundId' => $roundId])
            ->execute()
            ->fetchAll('assoc');

        return array_map(static fn (array $row): AvailabilityEntry => AvailabilityEntry::fromRow($row), $rows ?: []);
    }

    public function insertRound(int $seasonId, string $date, int $roundNumber): void
    {
        $this->queryFactory->newInsert('Round', [
            'SeasonId', 'Date', 'Number', 'AverageAbsent', 'Calculated', 'DrawClosed',
        ])
            ->values([
                'SeasonId' => $seasonId,
                'Date' => $date,
                'Number' => $roundNumber,
                'AverageAbsent' => 0,
                'Calculated' => 0,
                'DrawClosed' => 0,
            ])
            ->execute();
    }

    public function updateAverageAbsent(int $id, float $averageAbsent): void
    {
        $this->queryFactory->newUpdate('Round')
            ->set([
                'AverageAbsent' => $averageAbsent,
                'Calculated' => 1,
            ])
            ->where(['Id' => $id])
            ->execute();
    }

    public function existsWithDate(string $date): bool
    {
        $row = $this->queryFactory->newSelect('Round')
            ->select(['num' => 'COUNT(*)'])
            ->where(['Date' => $date])
            ->execute()
            ->fetch('assoc');

        return (int) ($row['num'] ?? 0) > 0;
    }

    public function exists(int $id): bool
    {
        $row = $this->queryFactory->newSelect('Round')
            ->select(['num' => 'COUNT(*)'])
            ->where(['Id' => $id])
            ->execute()
            ->fetch('assoc');

        return (int) ($row['num'] ?? 0) > 0;
    }

    /**
     * Base summary select aliased RND => Round, including a correlated match count.
     */
    private function summaryQuery(): SelectQuery
    {
        return $this->queryFactory->newSelectQuery()
            ->select([
                'id' => 'RND.Id',
                'number' => 'RND.Number',
                'date' => 'RND.Date',
                'averageAbsent' => $this->queryFactory->expr('ROUND(RND.AverageAbsent, 2)'),
                'calculated' => 'RND.Calculated',
                'matchCount' => $this->queryFactory->expr(
                    '(SELECT COUNT(*) FROM `Match` WHERE `Match`.`RoundId` = RND.Id)'
                ),
            ])
            ->from(['RND' => 'Round']);
    }
}
