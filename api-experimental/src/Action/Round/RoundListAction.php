<?php

declare(strict_types=1);

namespace App\Action\Round;

use App\Domain\Round\Service\RoundReader;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RoundListAction
{
    public function __construct(
        private RoundReader $roundReader,
        private JsonRenderer $renderer
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = []
    ): ResponseInterface {
        $seasonId = $request->getQueryParams()['seasonId'] ?? null;

        return $this->renderer->json(
            $response,
            $this->roundReader->getAll($seasonId !== null ? (int) $seasonId : null)
        );
    }
}
