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
        $items = $request->getQueryParams()['$top'] ?? null;
        $items = $items !== null ? (int) $items : null;
        $type = $args['type'] ?? null;
        switch ($type) {
            case 'general':
                $data = $this->rankingReader->get($items, true);
                break;
            case 'women':
                $data = $this->rankingReader->get($items, false, true);
                break;
            case 'veterans':
                $data = $this->rankingReader->get($items, false, false, true);
                break;
            case 'recreants':
                $data = $this->rankingReader->get($items, false, false, false, true);
                break;
            default:
                $data = $this->rankingReader->get($items, true, true, true, true);
                break;
        }

        return $this->renderer->json($response, $data);
    }
}
