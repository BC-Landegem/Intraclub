<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesSeason;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlayerSeasonStatisticResource;
use App\Http\Resources\SeasonResource;
use App\Models\PlayerSeasonStatistic;
use App\Models\Season;
use App\Services\RankingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Publieke seizoensgegevens en -statistieken.
 *
 * /seasons/{season}/statistics slikt zowel een id als `current`, zodat de
 * clubwebsite het lopende seizoen kan opvragen zonder eerst de lijst te halen.
 * De oude route /seasons/latest/statistics bestaat niet meer.
 *
 * Samen met /rankings zijn dit de twee routes die élk seizoen mogen tonen: het
 * zijn de twee helften van een eindstand (plaats + gemiddelde enerzijds, sets,
 * matchen en aanwezigheden anderzijds). Ze horen dus rij voor rij dezelfde
 * spelers te geven — daar zorgt RankingService::finalStanding() voor.
 */
class SeasonController extends Controller
{
    use ResolvesSeason;

    public function __construct(private readonly RankingService $rankingService) {}

    public function index(): AnonymousResourceCollection
    {
        $seasons = Season::query()->withCount('rounds')->orderBy('id')->get();

        // `players_count` is de lengte van de eindstand en niet het aantal
        // seizoensrijen. Het getal komt op de site naast een link naar die stand,
        // dus mag er niet "121 spelers" boven een tabel van 88 rijen staan.
        foreach ($seasons as $season) {
            $season->players_count = count($this->rankingService->finalStanding($season, membersOnly: false));
        }

        return SeasonResource::collection($seasons)
            ->additional(['meta' => ['current_season_id' => Season::current()?->id]]);
    }

    /**
     * Gesorteerd op aanwezigheid, dan gewonnen sets, dan basispunten — de
     * volgorde waarin de aanwezighedenlijst op de site staat.
     *
     * Enkel wie in de eindstand staat: geen gemiddelde op de laatste berekende
     * speeldag betekent dat de speler er dat seizoen uit was, en dan hoort het
     * seizoen niet op zijn fiche en hij niet in de stand. Zonder die eis gaf dit
     * endpoint met ?members=0 meer rijen dan /rankings — voor 33 spelers in
     * 2025-2026 bestond de helft van de vijf kolommen niet.
     */
    public function statistics(Request $request, string $season): AnonymousResourceCollection
    {
        $model = $this->seasonFromPath($season);
        $membersOnly = $request->boolean('members', true);

        $query = PlayerSeasonStatistic::query()
            ->with('player')
            ->join('players', 'players.id', '=', 'player_season_statistics.player_id')
            ->where('player_season_statistics.season_id', $model->id)
            ->whereIn(
                'player_season_statistics.player_id',
                array_keys($this->rankingService->finalStanding($model, $membersOnly)),
            )
            ->orderByDesc('player_season_statistics.rounds_present')
            ->orderByDesc('player_season_statistics.sets_won')
            ->orderByDesc('player_season_statistics.base_points')
            ->select('player_season_statistics.*');

        if ($membersOnly) {
            $query->where('players.is_member', true);
        }

        return PlayerSeasonStatisticResource::collection($query->get())
            ->additional(['meta' => ['season' => $this->seasonMeta($model)]]);
    }
}
