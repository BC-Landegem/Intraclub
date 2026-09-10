<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Wie stond dit seizoen met wie op dezelfde baan, en hoe vaak.
 *
 * Door de rotatie in `Game::LINE_UPS` speelt elke speler precies één set mét en twee
 * sets tégen elk van de andere drie op zijn baan. "Tegen wie speelde je" is hier dus
 * hetzelfde als "met wie deelde je een baan" — er valt niets te kiezen.
 *
 * Geteld worden *alle* wedstrijden, ook onvolledig ingevulde en ook iemands tweede
 * game van dezelfde avond. Dat wijkt bewust af van `Pairings` (enkel volledige, want
 * die rapporteert wie won) en van `SeasonCalculator` (enkel de eerste game, want een
 * invaller hoort geen statistieken bij te krijgen). Drie tellers, drie vragen: wie
 * samen op een baan stond, stond daar — of de score ingetikt raakte of die avond
 * meetelde verandert daar niets aan.
 *
 * Niets hiervan wordt opgeslagen. Het is een pure functie van de `games`-rijen, dus
 * een kolom zou enkel een tweede waarheid zijn — dezelfde afweging als bij `day_score`.
 */
class SeasonEncounters
{
    /**
     * Per speler hoe vaak hij met elke andere speler op een baan stond.
     *
     * @return array<int, array<int, int>>
     */
    public function forSeason(int $seasonId): array
    {
        $encounters = [];

        $games = DB::table('games')
            ->join('rounds', 'rounds.id', '=', 'games.round_id')
            ->where('rounds.season_id', $seasonId)
            ->get(['games.player1_id', 'games.player2_id', 'games.player3_id', 'games.player4_id']);

        foreach ($games as $game) {
            $this->remember($encounters, array_filter((array) $game));
        }

        return $encounters;
    }

    /**
     * Voeg één baan toe aan de telling.
     *
     * @param  array<int, array<int, int>>  $encounters
     * @param  array<int|string, int>  $playerIds
     */
    private function remember(array &$encounters, array $playerIds): void
    {
        foreach ($playerIds as $player) {
            foreach ($playerIds as $other) {
                if ($player !== $other) {
                    $encounters[$player][$other] = ($encounters[$player][$other] ?? 0) + 1;
                }
            }
        }
    }

    /**
     * De twee cijfers waarmee de twee lotingsystemen te vergelijken zijn: hoeveel
     * verschillende tegenstanders een speler gemiddeld zag, en hoe vaak hetzelfde
     * koppel elkaar in het slechtste geval tegenkwam.
     *
     * Het gemiddelde heeft een plafond dat losstaat van de loting: wie negen
     * wedstrijden speelt kan er hoogstens 27 verschillende tegenkomen. Het cijfer
     * zegt dus iets in vergelijking met een ander seizoen, niet op zichzelf.
     *
     * @return array{players: int, averageOpponents: float, highestRepeat: int}
     */
    public function spread(int $seasonId): array
    {
        $encounters = $this->forSeason($seasonId);

        if ($encounters === []) {
            return ['players' => 0, 'averageOpponents' => 0.0, 'highestRepeat' => 0];
        }

        $distinct = 0;
        $highestRepeat = 0;

        foreach ($encounters as $opponents) {
            $distinct += count($opponents);
            $highestRepeat = max($highestRepeat, ...array_values($opponents));
        }

        return [
            'players' => count($encounters),
            'averageOpponents' => round($distinct / count($encounters), 1),
            'highestRepeat' => $highestRepeat,
        ];
    }
}
