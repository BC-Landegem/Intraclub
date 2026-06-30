<?php

declare(strict_types=1);

namespace App\Action\Player;

use App\Domain\Player\Service\PlayerReader;
use App\Renderer\JsonRenderer;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PlayerReaderAction
{
    public function __construct(
        private PlayerReader $playerReader,
        private JsonRenderer $renderer
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $playerId = (int) $args['id'];
        $seasonId = $request->getQueryParams()['seasonId'] ?? null;

        $player = $this->playerReader->getPlayer($playerId, $seasonId !== null ? (int) $seasonId : null);

        if ($player === null) {
            return $this->renderer
                ->json($response, ['error' => ['message' => 'Speler niet gevonden']])
                ->withStatus(StatusCodeInterface::STATUS_NOT_FOUND);
        }

        return $this->renderer->json($response, $player);
    }
}
