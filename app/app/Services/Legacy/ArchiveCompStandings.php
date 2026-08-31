<?php

namespace App\Services\Legacy;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Herberekent de eindstand van de comp-seizoenen (2009-2013) uit de bewaarde uitslagen.
 *
 * De rekenregels zijn dezelfde als die van het huidige systeem, maar toegepast op het
 * oude format met vaste teams:
 *
 * - Het resultaat van een speler op een speeldag is het gemiddelde van de setscores van
 *   zijn team over de gespeelde sets, waarbij een set die voorbij 21 ging naar de
 *   21-puntenschaal herschaald wordt ({@see ArchiveGameStatistics}).
 * - Speelde hij twee keer op één speeldag, dan telt enkel de eerste wedstrijd.
 * - Was hij afwezig, dan krijgt hij het verliezersgemiddelde van die speeldag.
 * - De eindstand is het gemiddelde van zijn basispunten en zijn resultaat op elke
 *   speeldag van het seizoen, dus gedeeld door het aantal speeldagen plus één.
 *
 * Dat het écht deze regels waren is na te rekenen: voor drie van de vier seizoenen
 * reproduceert de berekening de bewaarde eindstand tot op de opslagprecisie na, voor
 * álle spelers (55/55, 82/82 en 79/79) — inclusief wie geen enkele wedstrijd speelde en
 * dus zestien of zeventien keer het verliezersgemiddelde kreeg.
 *
 * De uitzondering is 2010-2011. Daar kloppen de bewaarde tellers wél met de uitslagen
 * maar de bewaarde punten niet, en geen enkele afwijkende afwezigheids- of setregel
 * verklaart dat verschil: de stand is destijds blijven staan op uitslagen die daarna nog
 * verschoven zijn. Voor dat seizoen is de herberekening de enige stand die met de
 * bewaarde uitslagen overeenkomt. Elk verschil wordt gerapporteerd, zodat een volgende
 * import het opnieuw tegen de bewaarde cijfers afzet.
 */
class ArchiveCompStandings
{
    /** Verschillen kleiner dan dit zijn opslagprecisie, geen echte afwijking. */
    private const TOLERANCE = 0.0001;

    /**
     * Schrijft `final_points` voor elke comp-seizoensstatistiek.
     *
     * @return array<string, int> reden => aantal, voor het rapport van de import
     */
    public function recalculate(): array
    {
        $anomalieën = [];

        $seizoenen = DB::table('archive_seasons')->where('source', 'comp')->orderBy('id')->get();

        foreach ($seizoenen as $seizoen) {
            $speeldagen = DB::table('archive_rounds')
                ->where('archive_season_id', $seizoen->id)
                ->orderBy('date')
                ->get(['id', 'average_absent']);

            if ($speeldagen->isEmpty()) {
                continue;
            }

            $resultaten = $this->resultatenPerSpeler($speeldagen->pluck('id')->all());

            foreach ($this->statistiekenVan($seizoen->id) as $statistiek) {
                if ($statistiek->base_points === null) {
                    $anomalieën['comp-eindstand zonder basispunten'] ??= 0;
                    $anomalieën['comp-eindstand zonder basispunten']++;

                    continue;
                }

                $som = (float) $statistiek->base_points;

                foreach ($speeldagen as $speeldag) {
                    // Afwezig? Dan telt het verliezersgemiddelde van die speeldag mee.
                    $som += $resultaten[$statistiek->archive_player_id][$speeldag->id]
                        ?? (float) $speeldag->average_absent;
                }

                $punten = $som / ($speeldagen->count() + 1);

                if ($statistiek->final_points !== null
                    && abs((float) $statistiek->final_points - $punten) > self::TOLERANCE) {
                    $anomalieën['herberekende eindstand wijkt af van de bewaarde'] ??= 0;
                    $anomalieën['herberekende eindstand wijkt af van de bewaarde']++;
                }

                DB::table('archive_player_season_statistics')
                    ->where('id', $statistiek->id)
                    ->update(['final_points' => $punten]);
            }
        }

        return $anomalieën;
    }

    /**
     * Het resultaat van elke speler op elke speeldag. Speelde iemand twee keer op één
     * dag, dan houdt de eerste wedstrijd stand — `??=` laat de latere er niet meer over.
     *
     * @param  list<int>  $speeldagIds
     * @return array<int, array<int, float>> speler-id => speeldag-id => gemiddelde
     */
    private function resultatenPerSpeler(array $speeldagIds): array
    {
        $resultaten = [];

        $games = DB::table('archive_games')
            ->whereIn('archive_round_id', $speeldagIds)
            ->orderBy('id')
            ->get();

        foreach ($games as $game) {
            $statistiek = ArchiveGameStatistics::fromGame($game);

            if ($statistiek->setsPlayed === 0) {
                continue;
            }

            $teams = [
                1 => [$game->team1_player1_id, $game->team1_player2_id],
                2 => [$game->team2_player1_id, $game->team2_player2_id],
            ];

            foreach ($teams as $team => $spelers) {
                foreach ($spelers as $spelerId) {
                    $resultaten[$spelerId][$game->archive_round_id] ??= $statistiek->averageFor($team);
                }
            }
        }

        return $resultaten;
    }

    /** @return Collection<int, object> */
    private function statistiekenVan(int $seizoenId): Collection
    {
        return DB::table('archive_player_season_statistics')
            ->where('archive_season_id', $seizoenId)
            ->get(['id', 'archive_player_id', 'base_points', 'final_points']);
    }
}
