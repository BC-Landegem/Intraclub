<?php

declare(strict_types=1);

namespace App\Action\Match;

use App\Domain\Match\Service\MatchCreator;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class MatchCreatorAction
{
    public function __construct(
        private MatchCreator $matchCreator,
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
        $id = $this->matchCreator->createMatch($data);

        return $this->renderer->json($response, ['id' => $id]);
    }
}
