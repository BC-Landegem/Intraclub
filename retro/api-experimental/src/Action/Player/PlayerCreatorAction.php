<?php

namespace App\Action\Player;

use App\Domain\Player\Service\PlayerCreator;
use App\Renderer\JsonRenderer;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PlayerCreatorAction
{
    private JsonRenderer $renderer;

    private PlayerCreator $PlayerCreator;

    public function __construct(PlayerCreator $PlayerCreator, JsonRenderer $renderer)
    {
        $this->PlayerCreator = $PlayerCreator;
        $this->renderer = $renderer;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Extract the form data from the request body
        $data = (array) $request->getParsedBody();

        // Invoke the Domain with inputs and retain the result
        $PlayerId = $this->PlayerCreator->createPlayer($data);

        // Build the HTTP response
        return $this->renderer
            ->json($response, ['Player_id' => $PlayerId])
            ->withStatus(StatusCodeInterface::STATUS_CREATED);
    }
}