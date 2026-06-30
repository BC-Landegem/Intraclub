<?php

declare(strict_types=1);

namespace App\Action\Season;

use App\Domain\Season\Service\SeasonCreator;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SeasonCreatorAction
{
    public function __construct(
        private SeasonCreator $seasonCreator,
        private JsonRenderer $renderer
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = []
    ): ResponseInterface {
        $data = (array) $request->getParsedBody();
        $this->seasonCreator->createSeason((string) ($data['period'] ?? ''));

        return $this->renderer->json($response, ['status' => 'ok']);
    }
}
