<?php

namespace App\Http\Controllers\Api\Archive;

use App\Http\Controllers\Api\Concerns\ParsesIncludes;
use App\Http\Controllers\Controller;
use App\Http\Resources\Archive\ArchiveGameResource;
use App\Http\Resources\Archive\ArchivePlayerResource;
use App\Models\Archive\ArchiveGame;
use App\Models\Archive\ArchivePlayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Spelers uit de oude jaargangen, met hun geschiedenis per seizoen.
 */
class ArchivePlayerController extends Controller
{
    use ParsesIncludes;

    /** Zelfde include-mechaniek als de publieke spelersroute. */
    private const INCLUDES = ['games'];

    /**
     * Filter met `?player_id=` om te vinden wat het archief weet over iemand die
     * vandaag nog lid is — de sleutel om beide geschiedenissen aan elkaar te hangen.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $spelers = ArchivePlayer::query()
            ->when($request->integer('player_id'), fn ($query, int $id) => $query->where('player_id', $id))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return ArchivePlayerResource::collection($spelers);
    }

    /** @return array<string, mixed> */
    public function show(Request $request, ArchivePlayer $player): array
    {
        $seizoenen = $player->seasonStatistics()->with('season')->get()
            ->sortBy(fn ($stat): string => $stat->season->name)
            ->values();

        $data = (new ArchivePlayerResource($player))->resolve($request) + [
            'seasons' => $seizoenen->map(fn ($stat): array => [
                'season_id' => (int) $stat->archive_season_id,
                'season_name' => $stat->season->name,
                'base_points' => $stat->base_points === null ? null : (float) $stat->base_points,
                'sets' => ['won' => (int) $stat->sets_won, 'total' => (int) $stat->sets_played],
                'points' => ['won' => (int) $stat->points_won, 'total' => (int) $stat->points_played],
                'games' => ['won' => (int) $stat->games_won, 'total' => (int) $stat->games_played],
                'rounds' => ['present' => (int) $stat->rounds_present],
            ])->all(),
            'ranking_history' => $this->rankingHistory($player),
        ];

        if (in_array('games', $this->includes($request, self::INCLUDES), true)) {
            $data['games'] = ArchiveGameResource::collection($this->games($player))->resolve($request);
        }

        return ['data' => $data];
    }

    /**
     * Het voortschrijdende gemiddelde per speeldag. Enkel de generatie `intra_*`
     * hield dat bij; voor de comp-jaren blijft dit leeg.
     *
     * @return list<array<string, mixed>>
     */
    private function rankingHistory(ArchivePlayer $player): array
    {
        return $player->roundStatistics()
            ->join('archive_rounds', 'archive_rounds.id', '=', 'archive_player_round_statistics.archive_round_id')
            ->orderBy('archive_rounds.date')
            ->get([
                'archive_rounds.id as round_id',
                'archive_rounds.number',
                'archive_rounds.date',
                'archive_rounds.archive_season_id as season_id',
                'archive_player_round_statistics.average',
            ])
            ->map(fn ($rij): array => [
                'round_id' => (int) $rij->round_id,
                'season_id' => (int) $rij->season_id,
                'number' => (int) $rij->number,
                'date' => Carbon::parse($rij->date)->format('Y-m-d'),
                'average' => (float) $rij->average,
            ])
            ->all();
    }

    /** @return Collection<int, ArchiveGame> */
    private function games(ArchivePlayer $player)
    {
        return ArchiveGame::query()
            ->where(fn ($query) => $query
                ->orWhere('team1_player1_id', $player->id)
                ->orWhere('team1_player2_id', $player->id)
                ->orWhere('team2_player1_id', $player->id)
                ->orWhere('team2_player2_id', $player->id))
            ->with(['round', 'team1Player1', 'team1Player2', 'team2Player1', 'team2Player2'])
            ->join('archive_rounds', 'archive_rounds.id', '=', 'archive_games.archive_round_id')
            ->orderBy('archive_rounds.date')
            ->select('archive_games.*')
            ->get();
    }
}
