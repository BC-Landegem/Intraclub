<?php

declare(strict_types=1);

namespace App\Action\Player;

use App\Domain\Player\Service\PlayerUpdater;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class PlayerUpdaterAction
{
    public function __construct(
        private PlayerUpdater $playerUpdater,
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
        $data = (array) $request->getParsedBody();
        $this->playerUpdater->updatePlayer((int) $args['id'], $data);

        return $this->renderer->json($response, ['id' => (int) $args['id']]);
    }
}
