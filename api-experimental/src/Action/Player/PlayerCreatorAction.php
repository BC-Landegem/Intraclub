<?php

declare(strict_types=1);

namespace App\Action\Player;

use App\Domain\Player\Service\PlayerCreator;
use App\Renderer\JsonRenderer;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PlayerCreatorAction
{
    public function __construct(
        private PlayerCreator $playerCreator,
        private JsonRenderer $renderer
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $playerId = $this->playerCreator->createPlayer($data);

        return $this->renderer
            ->json($response, ['id' => $playerId])
            ->withStatus(StatusCodeInterface::STATUS_CREATED);
    }
}
