<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Round;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * De dagscore van een speler op één speeldag: het getal dat voor die speeldag in
 * zijn voortschrijdend gemiddelde terechtkomt.
 *
 *   gespeeld            → het herschaalde puntengemiddelde over zijn drie sets
 *   afwezig             → het verliezersgemiddelde van die speeldag
 *   uitgeloot, geen game → null, die speeldag telt niet mee
 *
 * Dit is exact wat `SeasonCalculator::calculatePlayer()` per speeldag in `$results`
 * zet, maar dan los opvraagbaar. Bewust niét opgeslagen: het is een pure functie
 * van de zes setstanden, dus een kolom zou alleen een tweede waarheid zijn die kan
 * gaan afwijken. Dat is het verschil met `player_round_statistics.rank`, die wél
 * bevroren moet worden omdat hij van álle spelers samen afhangt.
 */
class DayScores
{
    /**
     * Dagscore per speler voor één speeldag.
     *
     * @return array<int, float|null> speler-id => dagscore
     */
    public function forRound(Round $round): array
    {
        $round->loadMissing(['games', 'playerStatistics']);

        $scores = $this->fromGames($round->games->sortBy('id'));

        foreach ($round->playerStatistics as $statistic) {
            if (array_key_exists($statistic->player_id, $scores)) {
                continue;
            }

            $scores[$statistic->player_id] = $statistic->is_drawn_out
                ? null
                : $this->absentScore($round);
        }

        return $scores;
    }

    /**
     * Dagscore per speeldag voor één speler, over een heel seizoen.
     *
     * @return array<int, float|null> speeldag-id => dagscore
     */
    public function forPlayerSeason(int $playerId, int $seasonId): array
    {
        $perRound = $this->gamesOfPlayer($playerId, $seasonId);
        $drawnOut = $this->drawnOutPerRound($playerId, $seasonId);

        $scores = [];

        foreach (Round::query()->where('season_id', $seasonId)->orderBy('number')->get() as $round) {
            $games = $perRound->get($round->id);

            $scores[$round->id] = $games === null
                ? (($drawnOut[$round->id] ?? false) ? null : $this->absentScore($round))
                : ($this->fromGames($games)[$playerId] ?? null);
        }

        return $scores;
    }

    /**
     * @param  Collection<int, Game>  $games  op id gesorteerd
     * @return array<int, float>
     */
    private function fromGames(Collection $games): array
    {
        $scores = [];

        foreach ($games as $game) {
            // Een onvolledige game heeft geen zinnige dagscore: GameStatistics zou
            // de lege sets als 0 rekenen. SeasonCalculator laat zo'n speeldag ook
            // buiten de telling.
            if (! $game->is_complete) {
                continue;
            }

            $statistics = GameStatistics::fromGame($game);

            foreach ($game->playerIds() as $index => $playerId) {
                // De eerste game van de speeldag telt; speelt iemand er een tweede
                // (bijvoorbeeld als invaller), dan blijft die buiten beschouwing —
                // net als in SeasonCalculator.
                $scores[$playerId] ??= $statistics->averages[$index + 1];
            }
        }

        return $scores;
    }

    private function absentScore(Round $round): ?float
    {
        // Null zolang de speeldag niet berekend is: dan bestaat het
        // verliezersgemiddelde nog niet.
        return $round->average_absent === null ? null : (float) $round->average_absent;
    }

    /** @return Collection<int, Collection<int, Game>> speeldag-id => games */
    private function gamesOfPlayer(int $playerId, int $seasonId): Collection
    {
        return Game::query()
            ->join('rounds', 'rounds.id', '=', 'games.round_id')
            ->where('rounds.season_id', $seasonId)
            ->where(fn ($query) => $query
                ->orWhere('games.player1_id', $playerId)
                ->orWhere('games.player2_id', $playerId)
                ->orWhere('games.player3_id', $playerId)
                ->orWhere('games.player4_id', $playerId))
            ->orderBy('games.id')
            ->select('games.*')
            ->get()
            ->groupBy('round_id');
    }

    /** @return array<int, bool> speeldag-id => uitgeloot */
    private function drawnOutPerRound(int $playerId, int $seasonId): array
    {
        return DB::table('player_round_statistics')
            ->join('rounds', 'rounds.id', '=', 'player_round_statistics.round_id')
            ->where('rounds.season_id', $seasonId)
            ->where('player_round_statistics.player_id', $playerId)
            ->pluck('player_round_statistics.is_drawn_out', 'player_round_statistics.round_id')
            ->map(fn ($vlag): bool => (bool) $vlag)
            ->all();
    }
}
