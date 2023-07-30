<?php
namespace App\Domain\Player\Service;

use App\Domain\Player\Data\PlayerReaderResult;
use App\Domain\Player\Repository\PlayerRepository;
use DateTime;

final class PlayerReader
{

    private PlayerRepository $repository;

    public function __construct(PlayerRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Read a player.
     * 
     * @param int $playerId The player id
     * 
     * @return PlayerReaderResult The player data
     */
    public function getPlayer(int $playerId): PlayerReaderResult
    {
        $playerRow = $this->repository->getPlayerById($playerId);

        $player = new PlayerReaderResult();
        $player->id = (int) $playerRow['Id'];
        $player->firstname = (string) $playerRow['Firstname'];
        $player->name = (string) $playerRow['Name'];
        $player->member = (bool) $playerRow['Member'];
        $player->gender = (string) $playerRow['Gender'];
        $player->birthDate = new DateTime($playerRow['BirthDate']);
        $player->doubleRanking = (int) $playerRow['DoubleRanking'];

        return $player;
    }
}