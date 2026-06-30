<?php

declare(strict_types=1);

namespace App\Action\Player;

use App\Domain\Player\Service\PlayerFinder;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PlayerListAction
{
    public function __construct(
        private PlayerFinder $playerFinder,
        private JsonRenderer $renderer
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderer->json($response, $this->playerFinder->findAll());
    }
}
