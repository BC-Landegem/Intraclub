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
use App\Services\PlayerSeasonHistory;
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
 * Alles hier gaat over het lopende seizoen. Van vroeger blijft op een fiche enkel
 * de seizoenstabel over — plaats, gemiddelde, sets, matchen, aanwezig per seizoen,
 * dezelfde vijf kolommen als in de eindstand. Wedstrijden, klassementsverloop en
 * partnerbalans van een afgesloten seizoen zijn weg: ontdubbeld over de leden van
 * dat seizoen stond de volledige speeldaggeschiedenis er anders weer, met alle
 * namen en setstanden erbij. De grens staat als middleware in routes/api.php.
 *
 * Welke includes een route kent, staat in routes/api.php.
 *
 * Queryparameters: season, include (op de speler).
 */
class PlayerController extends Controller
{
    use ParsesIncludes;
    use ResolvesSeason;

    public function __construct(
        private readonly RankingHistory $rankingHistory,
        private readonly Pairings $pairings,
        private readonly PlayerSeasonHistory $seasonHistory,
    ) {}

    /**
     * Het huidige ledenbestand. Er is bewust geen ?members=0 meer: dat leverde een
     * bladerbare lijst van iedereen die ooit meespeelde. Wie in een afgesloten
     * seizoen meedeed staat nog gewoon in die eindstand — daar zijn /rankings en
     * /seasons/{id}/statistics voor, mét ?members=0.
     */
    public function index(): AnonymousResourceCollection
    {
        return PlayerResource::collection(
            Player::query()->members()->orderBy('first_name')->orderBy('last_name')->get()
        );
    }

    public function show(Request $request, Player $player): PlayerDetailResource
    {
        $season = $this->seasonFromQuery($request);
        $statistic = $this->statistic($player, $season);

        $extra = [];

        if ($this->wants($request, 'games')) {
            $extra['games'] = GameResource::collection($this->gamesFor($player, $season));
        }

        if ($this->wants($request, 'ranking_history')) {
            $extra['ranking_history'] = $this->rankingHistory->forPlayer($player->id, (int) $season?->id);
        }

        return (new PlayerDetailResource(
            $player,
            (new PlayerSeasonStatisticResource($statistic))->counters(),
            $this->seasonHistory->forPlayer($player),
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
