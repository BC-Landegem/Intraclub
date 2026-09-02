<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ParsesIncludes;
use App\Http\Controllers\Api\Concerns\ResolvesSeason;
use App\Http\Controllers\Controller;
use App\Http\Resources\GameResource;
use App\Http\Resources\RoundDetailResource;
use App\Http\Resources\RoundResource;
use App\Models\Round;
use App\Services\DayScores;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Publieke speeldaggegevens.
 *
 * De routes /rounds/latest en /rounds/latestCalculated bestaan niet meer. De
 * laatst berekende speeldag zit in meta.round van /rankings — daar werd hij ook
 * voor gebruikt — en wie de lijst wil filteren doet dat met ?calculated=1.
 *
 * Queryparameters: season, calculated, include.
 */
class RoundController extends Controller
{
    use ParsesIncludes;
    use ResolvesSeason;

    public function __construct(private readonly DayScores $dayScores) {}

    /**
     * `?include=attendances` hangt aan elke speeldag dezelfde aanwezigheidsrijen
     * als /rounds/{round}. Dat bestaat omdat het verloop van een seizoen daar
     * volledig in zit: wie wanneer aanwezig was, met dagscore, gemiddelde en
     * plaats. Zonder deze include haalde een consument dat per speler op — en dat
     * zijn honderden requests voor rijen die hier al in drie queries samen komen.
     *
     * @return array<string, mixed>
     */
    public function index(Request $request): array
    {
        $season = $this->seasonFromQuery($request);

        $query = Round::query()
            ->where('season_id', $season?->id)
            ->withCount([
                'games',
                'playerStatistics as players_present_count' => fn ($query) => $query->where('is_present', true),
                'playerStatistics as players_drawn_out_count' => fn ($query) => $query->where('is_drawn_out', true),
            ])
            ->orderBy('number');

        if ($request->has('calculated')) {
            $query->where('is_calculated', $request->boolean('calculated'));
        }

        $rounds = $query->get();
        $withAttendances = $this->wants($request, 'attendances');

        if ($withAttendances) {
            // De wedstrijden zitten erbij omdat de dagscore uit de setstanden komt.
            // Alles in één keer inladen houdt het bij een handvol queries voor de
            // hele lijst in plaats van een paar per speeldag.
            $rounds->load(['games', 'playerStatistics.player', 'season']);
        }

        return [
            'data' => $rounds->map(fn (Round $round): array => (new RoundResource($round))->resolve($request)
                + ($withAttendances
                    ? ['attendances' => RoundDetailResource::attendances($round, $this->dayScores->forRound($round))]
                    : []))->all(),
            'meta' => ['season' => $this->seasonMeta($season)],
        ];
    }

    public function show(Round $round): RoundDetailResource
    {
        $round->load([
            'season',
            'games' => fn ($query) => $query->orderBy('id'),
            'games.round',
            'games.player1',
            'games.player2',
            'games.player3',
            'games.player4',
            'playerStatistics.player',
        ]);

        return new RoundDetailResource($round, $this->dayScores->forRound($round));
    }

    public function games(Round $round): AnonymousResourceCollection
    {
        return GameResource::collection(
            $round->games()->with(['round', 'player1', 'player2', 'player3', 'player4'])->orderBy('id')->get()
        );
    }
}
