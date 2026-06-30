<?php

declare(strict_types=1);

namespace App\Action\Round;

use App\Domain\Round\Service\RoundCreator;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RoundCreatorAction
{
    public function __construct(
        private RoundCreator $roundCreator,
        private JsonRenderer $renderer
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = []
    ): ResponseInterface {
        $data = (array) $request->getParsedBody();
        $this->roundCreator->createRound((string) ($data['date'] ?? ''));

        return $this->renderer->json($response, ['status' => 'ok']);
    }
}
