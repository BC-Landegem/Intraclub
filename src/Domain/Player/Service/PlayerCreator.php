<?php
namespace App\Domain\Player\Service;

use App\Domain\Player\Repository\PlayerRepository;
use App\Domain\Player\Service\PlayerValidator;
use App\Factory\LoggerFactory;
use Psr\Log\LoggerInterface;

final class PlayerCreator
{
    private PlayerRepository $repository;

    private PlayerValidator $PlayerValidator;

    private LoggerInterface $logger;

    public function __construct(
        PlayerRepository $repository,
        PlayerValidator $PlayerValidator,
        LoggerFactory $loggerFactory
    ) {
        $this->repository = $repository;
        $this->PlayerValidator = $PlayerValidator;
        $this->logger = $loggerFactory
            ->addFileHandler('Player_creator.log')
            ->createLogger();
    }

    public function createPlayer(array $data): int
    {
        // // Input validation
        // $this->PlayerValidator->validatePlayer($data);

        // // Insert Player and get new Player ID
        // $PlayerId = $this->repository->insertPlayer($data);
        $PlayerId = 0;
        // Logging
        $this->logger->info(sprintf('Player created successfully: %s', $PlayerId));

        return $PlayerId;
    }
}