<?php

namespace App\Action\Player;

use App\Domain\Player\Data\PlayerReaderResult;
use App\Domain\Player\Service\PlayerReader;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PlayerReaderAction
{

    private PlayerReader $playerReader;

    private JsonRenderer $renderer;

    public function __construct(PlayerReader $playerReader, JsonRenderer $jsonRenderer)
    {
        $this->playerReader = $playerReader;
        $this->renderer = $jsonRenderer;
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        // Fetch parameters from the request
        $playerId = (int) $args['player_id'];
        // Invoke the domain and get the result
        $customer = $this->playerReader->getPlayer($playerId);

        // Transform result and render to json
        return $this->renderer->json($response, $customer);
    }

    private function transform(PlayerReaderResult $player): array
    {
        // return as array
        return [];
    }
}