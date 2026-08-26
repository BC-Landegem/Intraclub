<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GameResource;
use App\Http\Resources\PlayerResource;
use App\Http\Resources\PlayerStatisticsResource;
use App\Models\Player;
use App\Models\PlayerSeasonStatistic;
use App\Models\Season;
use App\Services\RankingHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Publieke spelersgegevens.
 */
class PlayerController extends Controller
{
    public function __construct(private readonly RankingHistory $rankingHistory) {}

    public function index(): AnonymousResourceCollection
    {
        return PlayerResource::collection(
            Player::query()->members()->orderBy('first_name')->orderBy('last_name')->get()
        );
    }

    /** @return array<string, mixed> */
    public function show(Request $request, Player $player): array
    {
        $seasonId = $request->integer('seasonId') ?: Season::current()?->id;

        $statistic = PlayerSeasonStatistic::query()
            ->with('player')
            ->where('season_id', $seasonId)
            ->where('player_id', $player->id)
            ->firstOrFail();

        $games = $player->games()
            ->join('rounds', 'rounds.id', '=', 'games.round_id')
            ->where('rounds.season_id', $seasonId)
            ->with(['round', 'player1', 'player2', 'player3', 'player4'])
            ->orderBy('games.id')
            ->select('games.*')
            ->get();

        $payload = (new PlayerStatisticsResource($statistic))->toArray($request);
        $payload['matches'] = GameResource::collection($games)->toArray($request);
        $payload['statistics']['rankingHistory'] = $this->rankingHistory->forPlayer($player->id, (int) $seasonId);

        return $payload;
    }
}
