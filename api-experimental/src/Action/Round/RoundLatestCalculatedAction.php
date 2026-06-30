<?php

declare(strict_types=1);

namespace App\Action\Round;

use App\Domain\Round\Service\RoundReader;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RoundLatestCalculatedAction
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
        return $this->renderer->json($response, $this->roundReader->getLastCalculated());
    }
}
