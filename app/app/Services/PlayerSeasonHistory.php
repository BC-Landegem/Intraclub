<?php

namespace App\Services;

use App\Models\Archive\ArchiveSeason;
use App\Models\Player;
use App\Models\PlayerSeasonStatistic;
use App\Models\Season;
use App\Services\Legacy\ArchiveStandings;
use Illuminate\Support\Facades\DB;

/**
 * De geschiedenis van één speler als seizoenstabel: per afgesloten seizoen de vijf
 * kolommen van de eindstand — plaats, gemiddelde, sets, matchen, aanwezig.
 *
 * Dit is alles wat er van vóór het lopende seizoen op een fiche staat. Het
 * klassementsverloop per speeldag is publiek verdwenen, ook voor leden en ook over
 * hun eigen seizoen: dat verloop van álle spelers samen was een vollediger dossier
 * dan de fiche zelf. Wat blijft is precies wat ook in de eindstand van dat seizoen
 * te lezen valt, en dat is opzet — het zijn letterlijk dezelfde rijen, één keer per
 * seizoen gerangschikt en één keer per speler.
 *
 * De plaats komt daarom niet uit `player_round_statistics.rank` (die is bevroren op
 * de leden van het moment van berekenen) maar uit de gepubliceerde eindstand, die
 * ook de deelnemers meeneemt die ondertussen gestopt zijn. Anders zou de fiche een
 * andere plaats claimen dan de tabel waarnaar ze verwijst.
 *
 * Beide generaties staan in één lijst, chronologisch: de seizoensnamen ("2013 -
 * 2014") sorteren lexicografisch al op datum. `is_archive` zegt bij welke stand een
 * rij hoort, want de twee id-reeksen staan los van elkaar en het oude format speelde
 * met vaste teams in best-of-3 — een gemiddelde van toen is een ander getal.
 */
class PlayerSeasonHistory
{
    public function __construct(
        private readonly RankingService $rankingService,
        private readonly ArchiveStandings $archiveStandings,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forPlayer(Player $player): array
    {
        $rows = array_merge($this->live($player), $this->archive($player));

        usort($rows, fn (array $a, array $b): int => strcmp($a['season_name'], $b['season_name']));

        return $rows;
    }

    /**
     * De afgesloten seizoenen van het huidige format. Het lopende seizoen hoort er
     * niet bij: dat staat volledig op de fiche zelf.
     *
     * @return list<array<string, mixed>>
     */
    private function live(Player $player): array
    {
        $currentId = Season::current()?->id;

        $statistics = PlayerSeasonStatistic::query()
            ->with('season')
            ->where('player_id', $player->id)
            ->when($currentId !== null, fn ($query) => $query->where('season_id', '!=', $currentId))
            ->get();

        $rows = [];

        foreach ($statistics as $statistic) {
            $season = $statistic->season;
            if ($season === null) {
                continue;
            }

            // ?members=0: de stand van een afgesloten seizoen bevat ook wie de club
            // ondertussen verlaten heeft, en de plaats hier moet die van die stand zijn.
            $place = $this->rankingService->finalStanding($season, membersOnly: false)[$player->id] ?? null;

            // Geen gemiddelde op de laatste berekende speeldag ⇒ niet in de eindstand,
            // dus ook niet op de fiche. Anders claimt de fiche deelname die de stand
            // ontkent.
            if ($place === null) {
                continue;
            }

            $rows[] = [
                'season_id' => (int) $season->id,
                'season_name' => $season->name,
                'is_archive' => false,
                'rank' => $place['rank'],
                'average' => round($place['average'], 2),
                'sets' => ['won' => (int) $statistic->sets_won, 'total' => (int) $statistic->sets_played],
                'games' => ['total' => (int) $statistic->games_played],
                'rounds' => ['present' => (int) $statistic->rounds_present],
            ];
        }

        return $rows;
    }

    /**
     * De veertien oude jaargangen. Alleen de seizoenen waarin deze speler een rij
     * heeft worden opgezocht: de plaats vraagt de hele stand van dat seizoen, en dat
     * hoeft niet veertien keer voor wie er drie speelde.
     *
     * @return list<array<string, mixed>>
     */
    private function archive(Player $player): array
    {
        $seasonIds = DB::table('archive_player_season_statistics as stat')
            ->join('archive_players as speler', 'speler.id', '=', 'stat.archive_player_id')
            ->where('speler.player_id', $player->id)
            ->distinct()
            ->pluck('stat.archive_season_id');

        if ($seasonIds->isEmpty()) {
            return [];
        }

        $rows = [];

        foreach (ArchiveSeason::query()->whereIn('id', $seasonIds)->orderBy('name')->get() as $season) {
            foreach ($this->archiveStandings->forSeason($season)->values() as $index => $rij) {
                if ((int) $rij->player_id !== $player->id) {
                    continue;
                }

                $rows[] = [
                    'season_id' => (int) $season->id,
                    'season_name' => $season->name,
                    'is_archive' => true,
                    'rank' => $index + 1,
                    'average' => round((float) $rij->average, 2),
                    'sets' => ['won' => (int) $rij->sets_won, 'total' => (int) $rij->sets_played],
                    'games' => ['total' => (int) $rij->games_played],
                    'rounds' => ['present' => (int) $rij->rounds_present],
                ];
            }
        }

        return $rows;
    }
}
