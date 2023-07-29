<?php
namespace App\Domain\Player\Service;

use App\Domain\Player\Repository\PlayerRepository;

final class PlayerValidator
{
    private PlayerRepository $repository;

    public function __construct(PlayerRepository $repository)
    {
        $this->repository = $repository;
    }
}