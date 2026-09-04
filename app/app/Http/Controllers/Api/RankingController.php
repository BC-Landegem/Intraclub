<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesSeason;
use App\Http\Controllers\Controller;
use App\Models\Round;
use App\Services\RankingService;
use Illuminate\Http\Request;

/**
 * Publieke klassementen (general, women, veterans, recreants).
 *
 * `meta.round` is de laatst berekende speeldag waarop deze stand staat. Daarmee
 * verdwijnt de aparte route /rounds/latestCalculated: wie de stand opvraagt
 * krijgt er meteen bij na welke speeldag ze geldt. Is er nog geen berekende
 * speeldag, dan is `meta.round` null. De basispunten bepalen dan nog wel de
 * volgorde, maar gemiddelden worden pas zichtbaar nadat een speler meespeelt.
 *
 * Queryparameters: season, round, limit, members, en op /rankings ook category.
 */
class RankingController extends Controller
{
    use ResolvesSeason;

    public function __construct(private readonly RankingService $rankingService) {}

    /** @return array<string, mixed> */
    public function index(Request $request): array
    {
        $category = $request->query('category');

        if (is_string($category) && $category !== '') {
            return $this->category($request, $category);
        }

        $ranking = $this->build($request, RankingService::CATEGORIES);

        return [
            'data' => $ranking['categories'],
            'meta' => $this->meta($ranking),
        ];
    }

    /** @return array<string, mixed> */
    public function category(Request $request, string $category): array
    {
        abort_unless(in_array($category, RankingService::CATEGORIES, true), 404);

        $ranking = $this->build($request, [$category]);

        return [
            'data' => $ranking['categories'][$category],
            'meta' => ['category' => $category] + $this->meta($ranking),
        ];
    }

    /**
     * @param  list<string>  $categories
     * @return array<string, mixed>
     */
    private function build(Request $request, array $categories): array
    {
        $season = $this->seasonFromQuery($request);

        $roundId = $request->integer('round') ?: null;
        if ($roundId !== null) {
            Round::findOrFail($roundId);
        }

        $limit = $request->integer('limit') ?: null;

        return $this->rankingService->get(
            seasonId: $season?->id,
            roundId: $roundId,
            limit: $limit,
            categories: $categories,
            // ?members=0 voor een afgesloten seizoen: wie toen meespeelde hoort in
            // die stand, ook al is hij nu geen lid meer. Anders klopt de erelijst
            // niet zodra een kampioen de club verlaat.
            membersOnly: $request->boolean('members', true),
        );
    }

    /**
     * @param  array<string, mixed>  $ranking
     * @return array<string, mixed>
     */
    private function meta(array $ranking): array
    {
        $round = $ranking['round'];

        return [
            'season' => $this->seasonMeta($ranking['season']),
            'round' => $round === null ? null : [
                'id' => $round->id,
                'number' => (int) $round->number,
                'date' => $round->date->format('Y-m-d'),
            ],
        ];
    }
}
