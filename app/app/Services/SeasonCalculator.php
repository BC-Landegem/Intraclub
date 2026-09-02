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
 * - Uitgeloot zonder game ⇒ die speeldag telt niet mee voor die speler (hij was er wél,
 *   maar mocht niet spelen). Dit wijkt bewust af van de legacy-API, die uitgelote spelers
 *   het verliezersgemiddelde gaf, net als afwezigen.
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
            $pointsPerSet = $season->points_per_set->value;

            $this->resetUncountedRounds($allRounds->skip($rounds->count()));

            $averageLosersPerRound = $this->calculateRoundAverages($rounds, $pointsPerSet);
            $lastRoundPosition = count($averageLosersPerRound);
            $drawnOut = $this->drawnOutPositions($rounds);

            $playerStatistics = PlayerSeasonStatistic::query()
                ->join('players', 'players.id', '=', 'player_season_statistics.player_id')
                ->where('player_season_statistics.season_id', $season->id)
                ->where('players.is_member', true)
                ->orderBy('players.first_name')
                ->orderBy('players.last_name')
                ->select('player_season_statistics.*')
                ->get();

            foreach ($playerStatistics as $playerStatistic) {
                $this->calculatePlayer($rounds, $playerStatistic, $averageLosersPerRound, $lastRoundPosition, $drawnOut, $pointsPerSet);
            }

            $this->writeRanks($rounds, $playerStatistics->pluck('player_id')->all());
        });
    }

    /**
     * Bevriest per speeldag de plaats in het algemene klassement.
     *
     * Dit gebeurt na de spelerslus omdat een rank pas te bepalen is wanneer alle
     * gemiddeldes van die speeldag geschreven zijn. Enkel de spelers die deze
     * berekening meenam krijgen een rank; rijen van wie ondertussen geen lid meer
     * is houden hun oude gemiddelde maar krijgen rank null, zodat een ex-lid geen
     * plaats meer inneemt in de historiek van de anderen.
     *
     * @param  Collection<int, Round>  $rounds
     * @param  list<int>  $playerIds
     */
    private function writeRanks(Collection $rounds, array $playerIds): void
    {
        if ($rounds->isEmpty() || $playerIds === []) {
            return;
        }

        $roundIds = $rounds->pluck('id')->all();

        DB::table('player_round_statistics')->whereIn('round_id', $roundIds)->update(['rank' => null]);

        foreach ($roundIds as $roundId) {
            $ids = DB::table('player_round_statistics')
                ->where('round_id', $roundId)
                ->whereIn('player_id', $playerIds)
                ->whereNotNull('average')
                ->orderByDesc('average')
                ->pluck('id')
                ->all();

            if ($ids === []) {
                continue;
            }

            // Eén query per speeldag. De id's komen uit de databank en worden hier
            // hard naar int gecast, dus er valt niets in te smokkelen.
            $cases = '';
            foreach ($ids as $position => $id) {
                $cases .= ' WHEN '.(int) $id.' THEN '.($position + 1);
            }

            DB::statement(
                'UPDATE player_round_statistics SET `rank` = CASE id'.$cases.' END WHERE id IN ('
                .implode(',', array_map('intval', $ids)).')'
            );
        }
    }

    /**
     * De aaneengesloten reeks speeldagen vanaf het begin van het seizoen die volledig
     * gespeeld zijn. Bij de eerste onafgewerkte (of lege) speeldag stopt de telling.
     *
     * @param  Collection<int, Round>  $rounds
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
     * @param  Collection<int, Round>  $rounds
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
            ->update(['average' => null, 'rank' => null]);
    }

    /**
     * Bepaal per speeldag het gemiddelde van de verliezende teams en sla het op.
     *
     * @param  Collection<int, Round>  $rounds
     * @return array<int, float> verliezersgemiddelde per speeldagpositie (1-based, volgorde = oplopend round-id)
     */
    private function calculateRoundAverages(Collection $rounds, int $pointsPerSet): array
    {
        $averages = [];
        $position = 1;

        foreach ($rounds as $round) {
            $games = $round->games()->orderBy('id')->get();

            $averageLosing = $games->isEmpty()
                ? 0.0
                : $games->sum(fn (Game $game): float => GameStatistics::fromGame($game, $pointsPerSet)->averageLosing) / $games->count();

            $round->update(['average_absent' => $averageLosing, 'is_calculated' => true]);
            $averages[$position] = $averageLosing;
            $position++;
        }

        return $averages;
    }

    /**
     * @param  Collection<int, Round>  $rounds
     * @param  array<int, float>  $averageLosersPerRound
     */
    private function calculatePlayer(
        Collection $rounds,
        PlayerSeasonStatistic $playerStatistic,
        array $averageLosersPerRound,
        int $lastRoundPosition,
        array $drawnOut,
        int $pointsPerSet,
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
                $results[$position] = $this->resultWithoutGame($position, $averageLosersPerRound, $drawnOut, $playerId);
                $position++;
            }
            if ($position !== (int) $game->round_number) {
                // Tweede game op dezelfde speeldag: overslaan.
                continue;
            }

            $statistics = GameStatistics::fromGame($game, $pointsPerSet);
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

        // Geen game (meer) op de resterende speeldagen.
        while ($position <= $lastRoundPosition) {
            $results[$position] = $this->resultWithoutGame($position, $averageLosersPerRound, $drawnOut, $playerId);
            $position++;
        }

        $now = now();
        $roundStatisticRows = [];
        foreach ($rounds as $round) {
            $sum = 0.0;
            $total = 0;
            for ($index = 0; $index <= $round->number; $index++) {
                // null = uitgeloot zonder game: die speeldag telt niet mee voor deze speler.
                if (($results[$index] ?? null) === null) {
                    continue;
                }
                $sum += $results[$index];
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

    /**
     * Resultaat voor een speeldag waarop de speler geen game heeft.
     *
     * Afwezig ⇒ het verliezersgemiddelde van die speeldag. Uitgeloot ⇒ null: hij was
     * er wél maar mocht niet spelen, dus die speeldag telt niet mee in zijn gemiddelde.
     * Wie later alsnog in een match belandt (bv. een laatkomer vult de match aan) heeft
     * een game en komt hier dus niet terecht — de vlag is daar niet bepalend.
     *
     * @param  array<int, float>  $averageLosersPerRound
     * @param  array<int, array<int, true>>  $drawnOut
     */
    private function resultWithoutGame(int $position, array $averageLosersPerRound, array $drawnOut, int $playerId): ?float
    {
        if (isset($drawnOut[$playerId][$position])) {
            return null;
        }

        return $averageLosersPerRound[$position];
    }

    /**
     * Wie was op welke speeldagpositie uitgeloot?
     *
     * @param  Collection<int, Round>  $rounds
     * @return array<int, array<int, true>> speler-id => speeldagpositie => true
     */
    private function drawnOutPositions(Collection $rounds): array
    {
        $positionByRoundId = [];
        foreach ($rounds->values() as $index => $round) {
            $positionByRoundId[$round->id] = $index + 1;
        }

        $drawnOut = [];
        $rows = DB::table('player_round_statistics')
            ->whereIn('round_id', array_keys($positionByRoundId))
            ->where('is_drawn_out', true)
            ->get(['player_id', 'round_id']);

        foreach ($rows as $row) {
            $drawnOut[$row->player_id][$positionByRoundId[$row->round_id]] = true;
        }

        return $drawnOut;
    }
}
