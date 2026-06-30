<?php

declare(strict_types=1);

namespace App\Action\Match;

use App\Domain\Match\Service\MatchFinder;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MatchListByRoundAction
{
    public function __construct(
        private MatchFinder $matchFinder,
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
        return $this->renderer->json($response, $this->matchFinder->findByRound((int) $args['id']));
    }
}
