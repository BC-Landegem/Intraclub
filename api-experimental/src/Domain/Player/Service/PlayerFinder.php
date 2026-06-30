<?php

declare(strict_types=1);

namespace App\Domain\Player\Service;

use App\Domain\Player\Data\PlayerSummary;
use App\Domain\Player\Repository\PlayerRepository;

final class PlayerFinder
{
    public function __construct(private PlayerRepository $repository)
    {
    }

    /**
     * @return array<int, PlayerSummary>
     */
    public function findAll(): array
    {
        return $this->repository->findMembers();
    }
}
