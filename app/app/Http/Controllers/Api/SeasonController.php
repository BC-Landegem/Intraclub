<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesSeason;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlayerSeasonStatisticResource;
use App\Http\Resources\SeasonResource;
use App\Models\PlayerSeasonStatistic;
use App\Models\Season;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Publieke seizoensgegevens en -statistieken.
 *
 * /seasons/{season}/statistics slikt zowel een id als `current`, zodat de
 * clubwebsite het lopende seizoen kan opvragen zonder eerst de lijst te halen.
 * De oude route /seasons/latest/statistics bestaat niet meer.
 */
class SeasonController extends Controller
{
    use ResolvesSeason;

    public function index(): AnonymousResourceCollection
    {
        return SeasonResource::collection(
            Season::query()->withCount(['rounds', 'playerStatistics'])->orderBy('id')->get()
        )->additional(['meta' => ['current_season_id' => Season::current()?->id]]);
    }

    /**
     * Gesorteerd op aanwezigheid, dan gewonnen sets, dan basispunten — de
     * volgorde waarin de aanwezighedenlijst op de site staat.
     */
    public function statistics(Request $request, string $season): AnonymousResourceCollection
    {
        $model = $this->seasonFromPath($season);

        $query = PlayerSeasonStatistic::query()
            ->with('player')
            ->join('players', 'players.id', '=', 'player_season_statistics.player_id')
            ->where('player_season_statistics.season_id', $model->id)
            ->orderByDesc('player_season_statistics.rounds_present')
            ->orderByDesc('player_season_statistics.sets_won')
            ->orderByDesc('player_season_statistics.base_points')
            ->select('player_season_statistics.*');

        if ($request->boolean('members', true)) {
            $query->where('players.is_member', true);
        }

        return PlayerSeasonStatisticResource::collection($query->get())
            ->additional(['meta' => ['season' => $this->seasonMeta($model)]]);
    }
}
