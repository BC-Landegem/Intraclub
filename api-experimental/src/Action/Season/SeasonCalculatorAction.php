<?php

declare(strict_types=1);

namespace App\Action\Season;

use App\Domain\Season\Service\SeasonCalculator;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SeasonCalculatorAction
{
    public function __construct(
        private SeasonCalculator $seasonCalculator,
        private JsonRenderer $renderer
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = []
    ): ResponseInterface {
        $this->seasonCalculator->calculateCurrentSeason();

        return $this->renderer->json($response, ['status' => 'ok']);
    }
}
