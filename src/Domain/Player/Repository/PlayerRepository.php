<?php

namespace App\Domain\Player\Repository;

use App\Factory\QueryFactory;
;

final class PlayerRepository
{
    private QueryFactory $queryFactory;

    public function __construct(QueryFactory $queryFactory)
    {
        $this->queryFactory = $queryFactory;
    }

    public function insertCustomer(array $player): int
    {
        return (int) $this->queryFactory->newInsert('players', $this->toRow($player))
            ->execute()
            ->lastInsertId();
    }
    private function toRow(array $player): array
    {
        return [
        ];
    }
}