<?php

namespace App\Http\Controllers\Api\Archive;

use App\Http\Controllers\Controller;
use App\Http\Resources\Archive\ArchiveRoundResource;
use App\Http\Resources\Archive\ArchiveSeasonResource;
use App\Models\Archive\ArchiveSeason;
use App\Services\Legacy\ArchiveStandings;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Gearchiveerde seizoenen (2009-2023), alleen-lezen.
 */
class ArchiveSeasonController extends Controller
{
    public function __construct(private readonly ArchiveStandings $standings) {}

    public function index(): AnonymousResourceCollection
    {
        return ArchiveSeasonResource::collection(
            ArchiveSeason::query()->withCount(['rounds', 'playerStatistics'])->orderBy('name')->get()
        );
    }

    public function rounds(ArchiveSeason $season): AnonymousResourceCollection
    {
        return ArchiveRoundResource::collection(
            $season->rounds()->withCount('games')->orderBy('date')->get()
        );
    }

    /**
     * De eindstand van het seizoen. Voor 2010-2011 is die leeg: dat seizoen werd
     * destijds nooit gearchiveerd, enkel de uitslagen bleven bewaard.
     *
     * @return array<string, mixed>
     */
    public function standings(ArchiveSeason $season): array
    {
        $laatsteSpeeldag = $this->standings->laatsteSpeeldag($season);

        return [
            'season' => new ArchiveSeasonResource($season),
            'afterRound' => $laatsteSpeeldag === null ? null : [
                'id' => $laatsteSpeeldag->id,
                'number' => $laatsteSpeeldag->number,
                'date' => $laatsteSpeeldag->date->format('Y-m-d'),
            ],
            'standings' => $this->standings->forSeason($season)->map(fn (object $rij): array => [
                'playerId' => $rij->archive_player_id,
                'currentPlayerId' => $rij->player_id,
                'firstName' => $rij->first_name,
                'name' => $rij->last_name,
                'ranking' => $rij->ranking,
                'average' => $rij->average === null ? null : (float) $rij->average,
                'basePoints' => $rij->base_points === null ? null : (float) $rij->base_points,
                'setsPlayed' => $rij->sets_played,
                'setsWon' => $rij->sets_won,
                'pointsPlayed' => $rij->points_played,
                'pointsWon' => $rij->points_won,
                'gamesPlayed' => $rij->games_played,
                'gamesWon' => $rij->games_won,
                'roundsPresent' => $rij->rounds_present,
            ])->all(),
        ];
    }
}
