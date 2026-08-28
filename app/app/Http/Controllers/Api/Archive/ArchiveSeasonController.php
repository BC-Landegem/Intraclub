<?php

namespace App\Http\Controllers\Api\Archive;

use App\Http\Controllers\Api\Concerns\ParsesIncludes;
use App\Http\Controllers\Controller;
use App\Http\Resources\Archive\ArchiveGameResource;
use App\Http\Resources\Archive\ArchiveRoundResource;
use App\Http\Resources\Archive\ArchiveSeasonResource;
use App\Models\Archive\ArchiveRound;
use App\Models\Archive\ArchiveSeason;
use App\Services\Legacy\ArchiveStandings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Gearchiveerde seizoenen (2009-2023), alleen-lezen.
 */
class ArchiveSeasonController extends Controller
{
    use ParsesIncludes;

    public function __construct(private readonly ArchiveStandings $standings) {}

    public function index(): AnonymousResourceCollection
    {
        return ArchiveSeasonResource::collection(
            ArchiveSeason::query()->withCount(['rounds', 'playerStatistics'])->orderBy('name')->get()
        );
    }

    /**
     * De speeldagen van een gearchiveerd seizoen.
     *
     * `?include=games` hangt de uitslagen er meteen aan. Een seizoen heeft een
     * twintigtal speeldagen, dus zonder deze include is een historiekpagina
     * bouwen een request per speeldag — voor data die toch bevroren is.
     *
     * @return array<string, mixed>
     */
    public function rounds(Request $request, ArchiveSeason $season): array
    {
        $rounds = $season->rounds()->withCount('games')->orderBy('date')->get();
        $withGames = $this->wants($request, 'games');

        if ($withGames) {
            $rounds->load([
                'games' => fn ($query) => $query->orderBy('id'),
                'games.team1Player1',
                'games.team1Player2',
                'games.team2Player1',
                'games.team2Player2',
            ]);
        }

        return [
            // resolve() en niet toArray(): dat laatste laat voorwaardelijke velden
            // (whenCounted, whenLoaded) als lege waarden in de response staan.
            'data' => $rounds->map(fn (ArchiveRound $round): array => (new ArchiveRoundResource($round))->resolve($request)
                + ($withGames
                    ? ['games' => ArchiveGameResource::collection($round->games)->resolve($request)]
                    : []))->all(),
            'meta' => ['season' => ['id' => $season->id, 'name' => $season->name]],
        ];
    }

    /**
     * De eindstand van het seizoen. Voor 2010-2011 is `data` leeg: dat seizoen
     * werd destijds nooit gearchiveerd, enkel de uitslagen bleven bewaard.
     *
     * @return array<string, mixed>
     */
    public function standings(ArchiveSeason $season): array
    {
        $laatsteSpeeldag = $this->standings->laatsteSpeeldag($season);

        return [
            'data' => $this->standings->forSeason($season)->map(fn (object $rij): array => [
                'archive_player_id' => (int) $rij->archive_player_id,
                'player_id' => $rij->player_id === null ? null : (int) $rij->player_id,
                'first_name' => $rij->first_name,
                'last_name' => $rij->last_name,
                'full_name' => trim("{$rij->first_name} {$rij->last_name}"),
                'ranking' => $rij->ranking,
                // Op twee cijfers zoals elk ander gemiddelde in deze API. De
                // sortering gebeurt in SQL op de ruwe waarde, dus dit raakt enkel
                // wat er getoond wordt.
                'average' => $rij->average === null ? null : round((float) $rij->average, 2),
                'base_points' => $rij->base_points === null ? null : (float) $rij->base_points,
                'sets' => [
                    'won' => (int) $rij->sets_won,
                    'total' => (int) $rij->sets_played,
                ],
                'points' => [
                    'won' => (int) $rij->points_won,
                    'total' => (int) $rij->points_played,
                ],
                'games' => [
                    'won' => (int) $rij->games_won,
                    'total' => (int) $rij->games_played,
                ],
                'rounds' => [
                    'present' => (int) $rij->rounds_present,
                ],
            ])->values()->all(),
            'meta' => [
                'season' => ['id' => $season->id, 'name' => $season->name, 'source' => $season->source],
                'after_round' => $laatsteSpeeldag === null ? null : [
                    'id' => $laatsteSpeeldag->id,
                    'number' => $laatsteSpeeldag->number,
                    'date' => $laatsteSpeeldag->date->format('Y-m-d'),
                ],
            ],
        ];
    }
}
