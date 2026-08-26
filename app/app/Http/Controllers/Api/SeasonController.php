<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlayerStatisticsResource;
use App\Models\PlayerSeasonStatistic;
use App\Models\Season;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Publieke seizoensgegevens en -statistieken.
 */
class SeasonController extends Controller
{
    public function index(): AnonymousResourceCollection|array
    {
        return Season::query()->orderBy('id')->get()
            ->map(fn (Season $season): array => ['id' => $season->id, 'name' => $season->name])
            ->all();
    }

    public function statistics(?Season $season = null): AnonymousResourceCollection
    {
        $season ??= Season::current();

        return PlayerStatisticsResource::collection(
            PlayerSeasonStatistic::query()
                ->with('player')
                ->join('players', 'players.id', '=', 'player_season_statistics.player_id')
                ->where('player_season_statistics.season_id', $season?->id)
                ->where('players.is_member', true)
                ->orderByDesc('player_season_statistics.rounds_present')
                ->orderByDesc('player_season_statistics.sets_won')
                ->orderByDesc('player_season_statistics.base_points')
                ->select('player_season_statistics.*')
                ->get()
        );
    }
}
