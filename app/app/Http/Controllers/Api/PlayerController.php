<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ParsesIncludes;
use App\Http\Controllers\Api\Concerns\ResolvesSeason;
use App\Http\Controllers\Controller;
use App\Http\Resources\GameResource;
use App\Http\Resources\PlayerDetailResource;
use App\Http\Resources\PlayerResource;
use App\Http\Resources\PlayerSeasonStatisticResource;
use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerSeasonStatistic;
use App\Models\Season;
use App\Services\Pairings;
use App\Services\RankingHistory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Publieke spelersgegevens.
 *
 * Wedstrijden en rankingverloop bestaan als eigen sub-resource én als
 * ?include=games,ranking_history op de speler zelf. Die include bestaat omdat de
 * spelerspagina alle drie tegelijk nodig heeft; een build-script dat enkel de
 * wedstrijden wil, haalt de sub-resource op en sleept de rest niet mee.
 *
 * Queryparameters: season, members (op de lijst), include (op de speler).
 */
class PlayerController extends Controller
{
    use ParsesIncludes;
    use ResolvesSeason;

    /** Wat ?include= mag bevatten. Al de rest geeft 422 en niet stilzwijgend niets. */
    private const INCLUDES = ['games', 'ranking_history'];

    public function __construct(
        private readonly RankingHistory $rankingHistory,
        private readonly Pairings $pairings,
    ) {}

    /**
     * Standaard enkel de huidige leden. ?members=0 geeft ook wie gestopt is —
     * nodig voor een pagina over een afgesloten seizoen, want daar hoort iemand
     * die vertrokken is nog gewoon in de eindstand.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Player::query()->orderBy('first_name')->orderBy('last_name');

        if ($request->boolean('members', true)) {
            $query->members();
        }

        return PlayerResource::collection($query->get());
    }

    public function show(Request $request, Player $player): PlayerDetailResource
    {
        $season = $this->seasonFromQuery($request);
        $statistic = $this->statistic($player, $season);
        $includes = $this->includes($request, self::INCLUDES);

        $extra = [];

        if (in_array('games', $includes, true)) {
            $extra['games'] = GameResource::collection($this->gamesFor($player, $season));
        }

        if (in_array('ranking_history', $includes, true)) {
            $extra['ranking_history'] = $this->rankingHistory->forPlayer($player->id, (int) $season?->id);
        }

        return (new PlayerDetailResource(
            $player,
            (new PlayerSeasonStatisticResource($statistic))->counters(),
            $extra,
        ))->additional(['meta' => ['season' => $this->seasonMeta($season)]]);
    }

    public function games(Request $request, Player $player): AnonymousResourceCollection
    {
        $season = $this->seasonFromQuery($request);

        return GameResource::collection($this->gamesFor($player, $season))
            ->additional(['meta' => ['season' => $this->seasonMeta($season)]]);
    }

    /** @return array<string, mixed> */
    public function rankingHistory(Request $request, Player $player): array
    {
        $season = $this->seasonFromQuery($request);

        return [
            'data' => $this->rankingHistory->forPlayer($player->id, (int) $season?->id),
            'meta' => ['season' => $this->seasonMeta($season)],
        ];
    }

    /**
     * Met wie deze speler speelde en met welk resultaat. Eén rij per andere speler,
     * met zowel de set als partner als de twee sets als tegenstander — dat is één
     * pass over dezelfde wedstrijden, dus één endpoint in plaats van twee.
     *
     * @return array<string, mixed>
     */
    public function pairings(Request $request, Player $player): array
    {
        $season = $this->seasonFromQuery($request);

        return [
            'data' => $this->pairings->forPlayer($player, $season?->id),
            'meta' => ['season' => $this->seasonMeta($season)],
        ];
    }

    private function statistic(Player $player, ?Season $season): PlayerSeasonStatistic
    {
        return PlayerSeasonStatistic::query()
            ->with('player')
            ->where('season_id', $season?->id)
            ->where('player_id', $player->id)
            ->firstOrFail();
    }

    /** @return Collection<int, Game> */
    private function gamesFor(Player $player, ?Season $season): Collection
    {
        return $player->games()
            ->join('rounds', 'rounds.id', '=', 'games.round_id')
            ->where('rounds.season_id', $season?->id)
            ->with(['round', 'player1', 'player2', 'player3', 'player4'])
            ->orderBy('rounds.number')
            ->orderBy('games.id')
            ->select('games.*')
            ->get();
    }
}
