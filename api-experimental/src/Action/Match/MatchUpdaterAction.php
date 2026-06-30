<?php

declare(strict_types=1);

namespace App\Action\Match;

use App\Domain\Match\Service\MatchUpdater;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MatchUpdaterAction
{
    public function __construct(
        private MatchUpdater $matchUpdater,
        private JsonRenderer $renderer
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = []
    ): ResponseInterface {
        $data = (array) $request->getParsedBody();
        $this->matchUpdater->updateMatch((int) $args['id'], $data);

        return $this->renderer->json($response, ['id' => (int) $args['id']]);
    }
}
