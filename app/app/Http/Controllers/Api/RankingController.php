<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RankingService;
use Illuminate\Http\Request;

/**
 * Publieke klassementen (algemeen, dames, veteranen, recreanten).
 */
class RankingController extends Controller
{
    public function __construct(private readonly RankingService $rankingService) {}

    /** @return array<string, mixed> */
    public function index(Request $request): array
    {
        return $this->build($request, RankingService::CATEGORIES);
    }

    /** @return array<string, mixed> */
    public function category(Request $request, string $category): array
    {
        abort_unless(in_array($category, RankingService::CATEGORIES, true), 404);

        return $this->build($request, [$category]);
    }

    /**
     * @param  list<string>  $categories
     * @return array<string, mixed>
     */
    private function build(Request $request, array $categories): array
    {
        $limit = $request->query('$top');
        $seasonId = $request->integer('seasonId') ?: null;
        $roundId = $request->integer('roundId') ?: null;

        $ranking = $this->rankingService->get(
            seasonId: $seasonId,
            roundId: $roundId,
            limit: $limit === null ? null : (int) $limit,
            categories: $categories,
        );

        return ['seasonId' => $ranking['seasonId']] + $ranking['categories'];
    }
}
