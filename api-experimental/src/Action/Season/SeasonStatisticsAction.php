<?php

declare(strict_types=1);

namespace App\Action\Season;

use App\Domain\Season\Service\SeasonStatisticsReader;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class SeasonStatisticsAction
{
    public function __construct(
        private SeasonStatisticsReader $seasonStatisticsReader,
        private JsonRenderer $renderer
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = []
    ): ResponseInterface {
        return $this->renderer->json($response, $this->seasonStatisticsReader->getStatistics());
    }
}
