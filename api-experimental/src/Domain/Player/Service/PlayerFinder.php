<?php

declare(strict_types=1);

namespace App\Domain\Player\Service;

use App\Domain\Player\Repository\PlayerRepository;

final class PlayerFinder
{
    public function __construct(private PlayerRepository $repository)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(bool $onlyMembers = true): array
    {
        return $this->repository->getAll($onlyMembers);
    }
}
