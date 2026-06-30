<?php

declare(strict_types=1);

namespace App\Domain\Match\Repository;

use App\Domain\Match\Data\MatchResult;
use App\Factory\QueryFactory;
use Cake\Database\Query\SelectQuery;

/**
 * Match repository (CakePHP query builder).
 */
final class MatchRepository
{
    public function __construct(private QueryFactory $queryFactory)
    {
    }

    /**
     * @return array<int, MatchResult>
     */
    public function findByRound(int $roundId): array
    {
        $rows = $this->matchQuery()
            ->where(['MT.RoundId' => $roundId])
            ->order(['MT.Id' => 'ASC'])
            ->execute()
            ->fetchAll('assoc');

        return array_map(static fn (array $row): MatchResult => MatchResult::fromRow($row), $rows ?: []);
    }

    /**
     * @return array<int, MatchResult>
     */
    public function findBySeasonAndPlayer(int $seasonId, int $playerId): array
    {
        $rows = $this->matchQuery()
            ->where(['RND.SeasonId' => $seasonId])
            ->andWhere([
                'OR' => [
                    'PL1H.Id' => $playerId,
                    'PL2H.Id' => $playerId,
                    'PL1A.Id' => $playerId,
                    'PL2A.Id' => $playerId,
                ],
            ])
            ->order(['MT.Id' => 'ASC'])
            ->execute()
            ->fetchAll('assoc');

        return array_map(static fn (array $row): MatchResult => MatchResult::fromRow($row), $rows ?: []);
    }

    public function create(int $roundId, int $playerId1, int $playerId2, int $playerId3, int $playerId4): int
    {
        return (int) $this->queryFactory
            ->newInsert('Match', [
                'RoundId', 'Player1Id', 'Player2Id', 'Player3Id', 'Player4Id',
                'Set1Home', 'Set1Away', 'Set2Home', 'Set2Away', 'Set3Home', 'Set3Away',
            ])
            ->values([
                'RoundId' => $roundId,
                'Player1Id' => $playerId1,
                'Player2Id' => $playerId2,
                'Player3Id' => $playerId3,
                'Player4Id' => $playerId4,
                'Set1Home' => 0,
                'Set1Away' => 0,
                'Set2Home' => 0,
                'Set2Away' => 0,
                'Set3Home' => 0,
                'Set3Away' => 0,
            ])
            ->execute()
            ->lastInsertId();
    }

    public function update(int $id, int $set1Home, int $set1Away, int $set2Home, int $set2Away, int $set3Home, int $set3Away): void
    {
        $this->queryFactory->newUpdate('Match')
            ->set([
                'Set1Home' => $set1Home,
                'Set1Away' => $set1Away,
                'Set2Home' => $set2Home,
                'Set2Away' => $set2Away,
                'Set3Home' => $set3Home,
                'Set3Away' => $set3Away,
            ])
            ->where(['Id' => $id])
            ->execute();
    }

    public function exists(int $id): bool
    {
        $row = $this->queryFactory->newSelect('Match')
            ->select(['num' => 'COUNT(*)'])
            ->where(['Id' => $id])
            ->execute()
            ->fetch('assoc');

        return (int) ($row['num'] ?? 0) > 0;
    }

    /**
     * Base select for a match including round number and all four players.
     */
    private function matchQuery(): SelectQuery
    {
        return $this->queryFactory->newSelectQuery()
            ->select([
                'id' => 'MT.Id',
                'roundId' => 'MT.RoundId',
                'roundNumber' => 'RND.Number',
                'set1Home' => 'MT.Set1Home',
                'set1Away' => 'MT.Set1Away',
                'set2Home' => 'MT.Set2Home',
                'set2Away' => 'MT.Set2Away',
                'set3Home' => 'MT.Set3Home',
                'set3Away' => 'MT.Set3Away',
                'player1Id' => 'PL1H.Id',
                'player1FirstName' => 'PL1H.FirstName',
                'player1Name' => 'PL1H.Name',
                'player2Id' => 'PL2H.Id',
                'player2FirstName' => 'PL2H.FirstName',
                'player2Name' => 'PL2H.Name',
                'player3Id' => 'PL1A.Id',
                'player3FirstName' => 'PL1A.FirstName',
                'player3Name' => 'PL1A.Name',
                'player4Id' => 'PL2A.Id',
                'player4FirstName' => 'PL2A.FirstName',
                'player4Name' => 'PL2A.Name',
            ])
            ->from(['MT' => 'Match'])
            ->innerJoin(['RND' => 'Round'], 'RND.Id = MT.RoundId')
            ->innerJoin(['PL1H' => 'Player'], 'PL1H.Id = MT.Player1Id')
            ->innerJoin(['PL2H' => 'Player'], 'PL2H.Id = MT.Player2Id')
            ->innerJoin(['PL1A' => 'Player'], 'PL1A.Id = MT.Player3Id')
            ->innerJoin(['PL2A' => 'Player'], 'PL2A.Id = MT.Player4Id');
    }
}
