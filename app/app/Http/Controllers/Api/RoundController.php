<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GameResource;
use App\Http\Resources\RoundDetailResource;
use App\Http\Resources\RoundResource;
use App\Models\Round;
use App\Models\Season;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Publieke speeldaggegevens.
 */
class RoundController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $seasonId = $request->integer('seasonId') ?: Season::current()?->id;

        return RoundResource::collection(
            Round::query()
                ->where('season_id', $seasonId)
                ->withCount('games')
                ->orderBy('id')
                ->get()
        );
    }

    public function latest(Request $request): ?RoundDetailResource
    {
        return $this->detail($this->query($request)->latest('id')->first());
    }

    public function latestCalculated(Request $request): ?RoundDetailResource
    {
        return $this->detail(
            $this->query($request)->where('is_calculated', true)->orderByDesc('number')->first()
        );
    }

    public function show(Round $round): RoundDetailResource
    {
        return $this->detail($round);
    }

    public function games(Round $round): AnonymousResourceCollection
    {
        return GameResource::collection(
            $round->games()->with(['round', 'player1', 'player2', 'player3', 'player4'])->orderBy('id')->get()
        );
    }

    private function query(Request $request): Builder
    {
        $seasonId = $request->integer('seasonId') ?: Season::current()?->id;

        return Round::query()->where('season_id', $seasonId);
    }

    private function detail(?Round $round): ?RoundDetailResource
    {
        if ($round === null) {
            return null;
        }

        $round->load([
            'games' => fn ($query) => $query->orderBy('id'),
            'games.round',
            'games.player1',
            'games.player2',
            'games.player3',
            'games.player4',
            'playerStatistics',
        ]);

        return new RoundDetailResource($round);
    }
}
