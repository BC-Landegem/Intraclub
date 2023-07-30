<?php

namespace App\Domain\Player\Repository;

use App\Factory\QueryFactory;
use DomainException;

;

final class PlayerRepository
{
    private QueryFactory $queryFactory;

    public function __construct(QueryFactory $queryFactory)
    {
        $this->queryFactory = $queryFactory;
    }

    public function insertPlayer(array $player): int
    {
        return (int) $this->queryFactory->newInsert('Player', $this->toRow($player))
            ->execute()
            ->lastInsertId();
    }

    public function getPlayerById(int $playerId): array
    {
        $query = $this->queryFactory->newSelect('Player');
        $query->select(
            [
                'Id',
                'Firstname',
                'Name',
                'Member',
                'Gender',
                'BirthDate',
                'DoubleRanking'
            ]
        );

        $query->where(['id' => $playerId]);

        $row = $query->execute()->fetch('assoc');

        if (!$row) {
            throw new DomainException(sprintf('Player not found: %s', $playerId));
        }

        return $row;
    }


    private function toRow(array $player): array
    {
        return [
        ];
    }
}