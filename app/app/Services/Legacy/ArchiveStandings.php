<?php

namespace App\Services\Legacy;

use App\Models\Archive\ArchiveRound;
use App\Models\Archive\ArchiveSeason;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * De eindstand van een gearchiveerd seizoen, zoals het toenmalige systeem ze toonde.
 *
 * Beide generaties bewaarden die stand anders. `intra_*` hield per speeldag het
 * voortschrijdende gemiddelde bij, dus de eindstand is de stand na de laatste
 * speeldag. `comp_*` bewaarde enkel een eindtotaal per seizoen, en had geen
 * klassementsevolutie. Voor 2010-2011 is er zelfs dat niet: dat seizoen werd
 * destijds nooit gearchiveerd, enkel de uitslagen bleven bewaard.
 */
class ArchiveStandings
{
    /** @return Collection<int, object> */
    public function forSeason(ArchiveSeason $season): Collection
    {
        $laatsteSpeeldag = $this->laatsteSpeeldag($season);

        return DB::table('archive_player_season_statistics as stat')
            ->join('archive_players as speler', 'speler.id', '=', 'stat.archive_player_id')
            ->leftJoin('archive_player_round_statistics as slot', function ($join) use ($laatsteSpeeldag): void {
                $join->on('slot.archive_player_id', '=', 'stat.archive_player_id')
                    ->where('slot.archive_round_id', '=', $laatsteSpeeldag?->id ?? 0);
            })
            ->where('stat.archive_season_id', $season->id)
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
                // De stand na de laatste speeldag; valt terug op het bewaarde
                // eindtotaal voor de generatie die geen evolutie bijhield.
                DB::raw('COALESCE(slot.average, stat.final_points) as average'),
            ])
            ->orderByRaw('average IS NULL, average DESC')
            ->orderBy('speler.last_name')
            ->get();
    }

    /** De speeldag waarop de eindstand van dit seizoen berekend is. */
    public function laatsteSpeeldag(ArchiveSeason $season): ?ArchiveRound
    {
        return $season->rounds()->orderByDesc('date')->first();
    }
}
