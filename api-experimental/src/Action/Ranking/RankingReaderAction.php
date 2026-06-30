<?php

declare(strict_types=1);

namespace App\Action\Ranking;

use App\Domain\Ranking\Service\RankingReader;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RankingReaderAction
{
    public function __construct(
        private RankingReader $rankingReader,
        private JsonRenderer $renderer,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args = [],
    ): ResponseInterface {
        $items = $request->getQueryParams()['$top'] ?? null;
        $items = $items !== null ? (int) $items : null;
        $type = $args['type'] ?? null;
        $data = match ($type) {
            'general' => $this->rankingReader->get($items, true),
            'women' => $this->rankingReader->get($items, false, true),
            'veterans' => $this->rankingReader->get($items, false, false, true),
            'recreants' => $this->rankingReader->get($items, false, false, false, true),
            default => $this->rankingReader->get($items, true, true, true, true),
        };

        return $this->renderer->json($response, $data);
    }
}
