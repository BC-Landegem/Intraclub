<?php

namespace App\Http\Controllers\Api\Archive;

use App\Http\Controllers\Controller;
use App\Http\Resources\Archive\ArchiveGameResource;
use App\Http\Resources\Archive\ArchivePlayerResource;
use App\Models\Archive\ArchiveGame;
use App\Models\Archive\ArchivePlayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Spelers uit de oude jaargangen, met hun geschiedenis per seizoen.
 */
class ArchivePlayerController extends Controller
{
    /**
     * Filter met `?playerId=` om te vinden wat het archief weet over iemand die
     * vandaag nog lid is — de sleutel om beide geschiedenissen aan elkaar te hangen.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $spelers = ArchivePlayer::query()
            ->when($request->integer('playerId'), fn ($query, int $id) => $query->where('player_id', $id))
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

        return [
            'player' => (new ArchivePlayerResource($player))->resolve($request),
            'seasons' => $seizoenen->map(fn ($stat): array => [
                'seasonId' => $stat->archive_season_id,
                'seasonName' => $stat->season->name,
                'basePoints' => $stat->base_points === null ? null : (float) $stat->base_points,
                'setsPlayed' => $stat->sets_played,
                'setsWon' => $stat->sets_won,
                'pointsPlayed' => $stat->points_played,
                'pointsWon' => $stat->points_won,
                'gamesPlayed' => $stat->games_played,
                'gamesWon' => $stat->games_won,
                'roundsPresent' => $stat->rounds_present,
            ])->all(),
            'rankingHistory' => $this->rankingHistory($player),
            'matches' => $request->boolean('withMatches')
                ? ArchiveGameResource::collection($this->games($player))->resolve($request)
                : [],
        ];
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
                'roundId' => $rij->round_id,
                'seasonId' => $rij->season_id,
                'number' => $rij->number,
                'date' => $rij->date,
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
