<?php

namespace App\Services;

use App\Models\Game;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Berekent de tussenstand van een seizoen: het verliezersgemiddelde per speeldag
 * (rounds.average_absent), het voortschrijdend gemiddelde per speler per speeldag
 * (player_round_statistics.average) en de seizoenstellers (player_season_statistics).
 *
 * 1:1 port van intraclub\managers\SeasonManager::calculateCurrentSeason uit de legacy-API.
 * Spelregels:
 * - Enkel afgewerkte speeldagen tellen mee: een speeldag telt zodra ze games heeft én
 *   álle games volledig zijn ingevuld. Er wordt gerekend tot de eerste speeldag die daar
 *   niet aan voldoet, zodat de tussenstand nooit een half ingevulde speeldag toont.
 * - Afwezig op een speeldag ⇒ de speler krijgt het verliezersgemiddelde van die speeldag.
 * - Meerdere games op één speeldag ⇒ enkel de eerste (laagste id) telt.
 * - De stand na speeldag N = gemiddelde van (basispunten + resultaat speeldag 1..N).
 * - Enkel leden mét een seizoensstatistiek-record worden berekend.
 */
class SeasonCalculator
{
    public function calculate(Season $season): void
    {
        DB::transaction(function () use ($season): void {
            $allRounds = $season->rounds()->with('games')->orderBy('id')->get();
            $rounds = $this->completedRounds($allRounds);

            $this->resetUncountedRounds($allRounds->skip($rounds->count()));

            $averageLosersPerRound = $this->calculateRoundAverages($rounds);
            $lastRoundPosition = count($averageLosersPerRound);

            $playerStatistics = PlayerSeasonStatistic::query()
                ->join('players', 'players.id', '=', 'player_season_statistics.player_id')
                ->where('player_season_statistics.season_id', $season->id)
                ->where('players.is_member', true)
                ->orderBy('players.first_name')
                ->orderBy('players.last_name')
                ->select('player_season_statistics.*')
                ->get();

            foreach ($playerStatistics as $playerStatistic) {
                $this->calculatePlayer($rounds, $playerStatistic, $averageLosersPerRound, $lastRoundPosition);
            }
        });
    }

    /**
     * De aaneengesloten reeks speeldagen vanaf het begin van het seizoen die volledig
     * gespeeld zijn. Bij de eerste onafgewerkte (of lege) speeldag stopt de telling.
     *
     * @param Collection<int, Round> $rounds
     * @return Collection<int, Round>
     */
    private function completedRounds(Collection $rounds): Collection
    {
        return $rounds->takeWhile(
            fn (Round $round): bool => $round->games->isNotEmpty()
                && $round->games->every(fn (Game $game): bool => $game->is_complete)
        )->values();
    }

    /**
     * Wis de berekende waarden van speeldagen die (nog) niet meetellen, zodat de
     * publieke tussenstand niet op verouderde cijfers blijft staan.
     *
     * @param Collection<int, Round> $rounds
     */
    private function resetUncountedRounds(Collection $rounds): void
    {
        if ($rounds->isEmpty()) {
            return;
        }

        $roundIds = $rounds->pluck('id');

        Round::whereIn('id', $roundIds)->update([
            'average_absent' => null,
            'is_calculated' => false,
        ]);

        DB::table('player_round_statistics')
            ->whereIn('round_id', $roundIds)
            ->update(['average' => null]);
    }

    /**
     * Bepaal per speeldag het gemiddelde van de verliezende teams en sla het op.
     *
     * @param Collection<int, Round> $rounds
     * @return array<int, float> verliezersgemiddelde per speeldagpositie (1-based, volgorde = oplopend round-id)
     */
    private function calculateRoundAverages(Collection $rounds): array
    {
        $averages = [];
        $position = 1;

        foreach ($rounds as $round) {
            $games = $round->games()->orderBy('id')->get();

            $averageLosing = $games->isEmpty()
                ? 0.0
                : $games->sum(fn (Game $game): float => GameStatistics::fromGame($game)->averageLosing) / $games->count();

            $round->update(['average_absent' => $averageLosing, 'is_calculated' => true]);
            $averages[$position] = $averageLosing;
            $position++;
        }

        return $averages;
    }

    /**
     * @param Collection<int, Round> $rounds
     * @param array<int, float> $averageLosersPerRound
     */
    private function calculatePlayer(
        Collection $rounds,
        PlayerSeasonStatistic $playerStatistic,
        array $averageLosersPerRound,
        int $lastRoundPosition,
    ): void {
        $playerId = $playerStatistic->player_id;

        // Resultaat per speeldagpositie; index 0 = basispunten.
        $results = [0 => (float) $playerStatistic->base_points];
        $position = 1;

        $counters = [
            'sets_played' => 0,
            'sets_won' => 0,
            'points_played' => 0,
            'points_won' => 0,
            'rounds_present' => 0,
            'games_played' => 0,
        ];

        $games = Game::query()
            ->join('rounds', 'rounds.id', '=', 'games.round_id')
            ->whereIn('games.round_id', $rounds->pluck('id'))
            ->where(fn ($query) => $query
                ->orWhere('games.player1_id', $playerId)
                ->orWhere('games.player2_id', $playerId)
                ->orWhere('games.player3_id', $playerId)
                ->orWhere('games.player4_id', $playerId))
            ->orderBy('games.id')
            ->select('games.*', 'rounds.number as round_number')
            ->get();

        foreach ($games as $game) {
            while ($game->round_number > $position) {
                // Afwezig op deze speeldag: verliezersgemiddelde toekennen.
                $results[$position] = $averageLosersPerRound[$position];
                $position++;
            }
            if ($position !== (int) $game->round_number) {
                // Tweede game op dezelfde speeldag: overslaan.
                continue;
            }

            $statistics = GameStatistics::fromGame($game);
            $slot = array_search($playerId, $game->playerIds(), true) + 1;

            $results[$position] = $statistics->averages[$slot];
            $counters['sets_won'] += $statistics->setsWon[$slot];
            $counters['points_won'] += $statistics->pointsWon[$slot];
            $counters['rounds_present']++;
            $counters['games_played']++;
            $counters['points_played'] += $statistics->totalPoints;
            $counters['sets_played'] += 3;

            $position++;
        }

        // Afwezig op de laatste speeldagen.
        while ($position <= $lastRoundPosition) {
            $results[$position] = $averageLosersPerRound[$position];
            $position++;
        }

        $now = now();
        $roundStatisticRows = [];
        foreach ($rounds as $round) {
            $sum = 0.0;
            $total = 0;
            for ($index = 0; $index <= $round->number; $index++) {
                $sum += $results[$index] ?? 0;
                $total++;
            }

            $roundStatisticRows[] = [
                'round_id' => $round->id,
                'player_id' => $playerId,
                'average' => $sum / $total,
                'is_present' => false,
                'is_drawn_out' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Bestaande rijen behouden hun aanwezigheidsvlaggen; enkel het gemiddelde wordt bijgewerkt.
        DB::table('player_round_statistics')->upsert(
            $roundStatisticRows,
            ['round_id', 'player_id'],
            ['average', 'updated_at'],
        );

        $playerStatistic->update($counters);
    }
}
