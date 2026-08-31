<?php

namespace App\Services\Legacy;

use App\Models\Archive\ArchiveRound;
use App\Models\Archive\ArchiveSeason;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * De eindstand van een gearchiveerd seizoen.
 *
 * Beide generaties bewaarden die stand anders. `intra_*` hield per speeldag het
 * voortschrijdende gemiddelde bij, dus de eindstand is de stand na de laatste
 * speeldag. `comp_*` bewaarde enkel een eindtotaal per seizoen en had geen
 * klassementsevolutie; dat totaal wordt bij de import herberekend uit de
 * uitslagen ({@see ArchiveCompStandings}), want voor 2010-2011 kloppen de
 * bewaarde punten niet met de bewaarde wedstrijden. Alle veertien seizoenen
 * hebben daarmee een stand; 2010-2011 met 73 spelers.
 *
 * Wie op de laatste speeldag geen gemiddelde heeft, staat niet in de stand. Dat
 * zijn twee groepen die dezelfde behandeling verdienen: 240 rijen "ingeschreven,
 * nooit gespeeld" (seizoensrij met basispunten, geen enkele speeldagrij) en 16
 * rijen van wie halverwege stopte — beide generaties stopten simpelweg met
 * speeldagrijen schrijven, en dát is het spoor dat een uitschrijving achterliet.
 * Ze stonden toen ook nergens: 2018-2019 gaat van 142 namen naar 81, 2022-2023
 * van 85 naar 79. De vier comp-seizoenen zijn niet geraakt, die hebben een
 * `final_points`.
 */
class ArchiveStandings
{
    /**
     * De stand na de laatste speeldag; valt terug op het bewaarde eindtotaal voor
     * de generatie die geen evolutie bijhield.
     */
    private const AVERAGE = 'COALESCE(slot.average, stat.final_points)';

    /** @return Collection<int, object> */
    public function forSeason(ArchiveSeason $season): Collection
    {
        return $this->query($season)
            ->select([
                'stat.archive_player_id',
                'speler.first_name',
                'speler.last_name',
                'speler.player_id',
                'speler.ranking',
                'stat.base_points',
                'stat.sets_played',
                'stat.sets_won',
                'stat.points_played',
                'stat.points_won',
                'stat.games_played',
                'stat.games_won',
                'stat.rounds_present',
                DB::raw(self::AVERAGE.' as average'),
            ])
            ->orderByDesc('average')
            ->orderBy('speler.last_name')
            ->get();
    }

    /**
     * Hoeveel spelers in de eindstand van dit seizoen staan.
     *
     * Dit is wat `players_count` op /archive/seasons toont, en niet het aantal
     * seizoensrijen: dat getal komt naast een link naar de eindstand te staan, en
     * "142 spelers" boven een tabel van 81 rijen is precies de stille afwijking
     * waar een consument op stukloopt.
     */
    public function countForSeason(ArchiveSeason $season): int
    {
        return $this->query($season)->count();
    }

    /** De speeldag waarop de eindstand van dit seizoen berekend is. */
    public function laatsteSpeeldag(ArchiveSeason $season): ?ArchiveRound
    {
        return $season->rounds()->orderByDesc('date')->first();
    }

    /**
     * De rijen die in de eindstand horen, zonder select of sortering — zo geven
     * forSeason() en countForSeason() gegarandeerd dezelfde verzameling.
     */
    private function query(ArchiveSeason $season): Builder
    {
        $laatsteSpeeldag = $this->laatsteSpeeldag($season);

        return DB::table('archive_player_season_statistics as stat')
            ->join('archive_players as speler', 'speler.id', '=', 'stat.archive_player_id')
            ->leftJoin('archive_player_round_statistics as slot', function ($join) use ($laatsteSpeeldag): void {
                $join->on('slot.archive_player_id', '=', 'stat.archive_player_id')
                    ->where('slot.archive_round_id', '=', $laatsteSpeeldag?->id ?? 0);
            })
            ->where('stat.archive_season_id', $season->id)
            // Geen eindgemiddelde ⇒ geen plaats in de eindstand. De alias `average`
            // bestaat hier nog niet, dus de uitdrukking staat er voluit: in een
            // count() vervalt de select en zou een alias in de WHERE breken.
            ->whereRaw(self::AVERAGE.' IS NOT NULL');
    }
}
