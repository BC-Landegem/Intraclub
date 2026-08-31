<?php

namespace App\Console\Commands;

use App\Enums\Gender;
use App\Services\Legacy\ArchivePerson;
use App\Services\Legacy\ArchivePlayerMatcher;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Schrijft de spelermapping tussen de drie generaties naar een CSV om na te kijken.
 *
 * Rijen met status AMBIGU of VOORSTEL vragen een beslissing; leg die vast in
 * database/legacy/player-map-overrides.php en draai dit opnieuw. Zolang er zulke
 * rijen zijn, weigert `intraclub:import-archive` te draaien.
 */
class BuildPlayerMap extends Command
{
    protected $signature = 'intraclub:player-map
        {--output= : Pad voor het CSV-bestand (standaard database/legacy/player-map.csv)}';

    protected $description = 'Genereert de spelermapping tussen comp_*, intra_* en het huidige ledenbestand.';

    /** Rijen die aandacht vragen komen bovenaan. */
    private const VOLGORDE = ['AMBIGU' => 0, 'VOORSTEL' => 1, 'NIEUW' => 3, 'BEVESTIGD' => 4, 'GEKOPPELD' => 5];

    public function handle(ArchivePlayerMatcher $matcher): int
    {
        $uitvoer = $this->option('output') ?: database_path('legacy/player-map.csv');
        $archive = DB::connection('archive');

        $personen = $matcher->persons();
        $compActiviteit = $this->compActiviteit($archive);
        $intraActiviteit = $this->intraActiviteit($archive);
        $spelerActiviteit = $this->spelerActiviteit();
        $spelers = DB::table('players')->get()->keyBy('id');

        usort($personen, fn (ArchivePerson $a, ArchivePerson $b): int => [self::VOLGORDE[$a->status], $a->fullName()]
            <=> [self::VOLGORDE[$b->status], $b->fullName()]);

        $bestand = @fopen($uitvoer, 'w');
        if ($bestand === false) {
            $this->error("Kan {$uitvoer} niet schrijven. Staat het bestand nog open in Excel?");

            return self::FAILURE;
        }

        fwrite($bestand, "\xEF\xBB\xBF"); // BOM, zodat Excel de accenten juist leest
        $this->schrijfRij($bestand, [
            'status', 'score', 'bevestigd_player_id',
            'voornaam', 'naam', 'geslacht',
            'comp_id', 'comp_koppeling', 'comp_wedstrijden', 'comp_van', 'comp_tot',
            'intra_id', 'intra_wedstrijden', 'intra_seizoenen', 'intra_van', 'intra_tot', 'intra_klassement',
            'player_id', 'player_naam', 'player_geboortedatum', 'player_wedstrijden',
            'opmerking',
        ]);

        $telling = [];
        foreach ($personen as $persoon) {
            $compAct = $persoon->comp === null ? null : ($compActiviteit[$persoon->comp->ID] ?? null);
            $intraAct = $persoon->intra === null ? null : ($intraActiviteit[$persoon->intra->id] ?? null);
            $speler = $persoon->playerId === null ? null : $spelers->get($persoon->playerId);
            $spelerAct = $persoon->playerId === null ? null : ($spelerActiviteit[$persoon->playerId] ?? null);

            $opmerkingen = $persoon->notes;
            if ($compAct === null && $intraAct === null) {
                $opmerkingen[] = 'geen enkele wedstrijd in de oude systemen';
            }
            if ($speler !== null && $persoon->gender !== null) {
                $opmerkingen = [...$opmerkingen, ...$this->geslachtsConflict($persoon, $speler)];
            }

            $this->schrijfRij($bestand, [
                $persoon->status,
                $persoon->score,
                in_array($persoon->status, ['GEKOPPELD', 'BEVESTIGD'], true) ? $persoon->playerId : '',
                $persoon->firstName,
                $persoon->lastName,
                self::geslachtLabel($persoon->gender),
                $persoon->comp?->ID,
                $persoon->compLink,
                $compAct?->wedstrijden,
                $compAct?->van,
                $compAct?->tot,
                $persoon->intra?->id,
                $intraAct?->wedstrijden,
                $intraAct?->seizoenen,
                $intraAct?->van,
                $intraAct?->tot,
                $persoon->ranking,
                $persoon->playerId,
                $speler === null ? '' : "{$speler->first_name} {$speler->last_name}",
                $speler->birth_date ?? '',
                $spelerAct?->wedstrijden,
                implode(' | ', $opmerkingen),
            ]);

            $telling[$persoon->status] = ($telling[$persoon->status] ?? 0) + 1;
        }
        fclose($bestand);

        $this->info(sprintf('Geschreven: %s', realpath($uitvoer)));
        $this->newLine();
        foreach (array_keys(self::VOLGORDE) as $status) {
            $this->line(sprintf('  %-10s %4d', $status, $telling[$status] ?? 0));
        }
        $this->line(sprintf('  %-10s %4d', 'totaal', array_sum($telling)));

        $openstaand = ($telling['AMBIGU'] ?? 0) + ($telling['VOORSTEL'] ?? 0);
        if ($openstaand > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '%d rij(en) vragen nog een beslissing; leg ze vast in database/legacy/player-map-overrides.php.',
                $openstaand,
            ));
        }

        $this->meldDubbels($matcher, $spelers, $spelerActiviteit);

        return self::SUCCESS;
    }

    /** Het geslacht zoals een mens het in het rapport wil lezen. */
    private static function geslachtLabel(?string $waarde): string
    {
        return $waarde === null ? '' : Gender::from($waarde)->getLabel();
    }

    /** @return list<string> */
    private function geslachtsConflict(ArchivePerson $persoon, object $speler): array
    {
        // Een geslachtsconflict is een sterk signaal dat de koppeling fout is.
        // Beide kanten staan sinds de normalisatie in dezelfde vorm, dus dit mag een
        // gewone vergelijking zijn; het rapport zelf blijft Nederlands, want het wordt
        // in Excel door een mens nagekeken.
        return $speler->gender === $persoon->gender
            ? []
            : [sprintf(
                'LET OP geslacht verschilt: oud %s, nieuw %s',
                self::geslachtLabel($persoon->gender),
                self::geslachtLabel($speler->gender),
            )];
    }

    /**
     * @param  Collection<int, object>  $spelers
     * @param  array<int, object>  $activiteit
     */
    private function meldDubbels(ArchivePlayerMatcher $matcher, $spelers, array $activiteit): void
    {
        $dubbels = $matcher->duplicatePlayers();
        if ($dubbels === []) {
            return;
        }

        $this->newLine();
        $this->line('Nog te doen in productie: deze spelers staan dubbel en moeten samengevoegd worden');
        $this->line('(wedstrijden en statistieken mee verhuizen), anders blijft de splitsing bestaan.');

        foreach ($dubbels as $dubbel => $blijft) {
            $speler = $spelers->get($dubbel);
            $this->line(sprintf(
                '  id %d -> %d  (%s, %d wedstrijden op het dubbel)',
                $dubbel,
                $blijft,
                $speler === null ? '?' : "{$speler->first_name} {$speler->last_name}",
                $activiteit[$dubbel]->wedstrijden ?? 0,
            ));
        }
    }

    /** @return array<int, object> */
    private function compActiviteit(ConnectionInterface $archive): array
    {
        return $this->perSpeler($archive->select('
            SELECT s.speler_id, COUNT(*) AS wedstrijden, MIN(d.datum) AS van, MAX(d.datum) AS tot
            FROM (
                SELECT team1_speler1 AS speler_id, speeldag FROM comp_uitslagen
                UNION ALL SELECT team1_speler2, speeldag FROM comp_uitslagen
                UNION ALL SELECT team2_speler1, speeldag FROM comp_uitslagen
                UNION ALL SELECT team2_speler2, speeldag FROM comp_uitslagen
            ) s
            JOIN comp_dagen d ON d.ID = s.speeldag
            GROUP BY s.speler_id
        '));
    }

    /** @return array<int, object> */
    private function intraActiviteit(ConnectionInterface $archive): array
    {
        return $this->perSpeler($archive->select('
            SELECT s.speler_id, COUNT(*) AS wedstrijden, MIN(d.datum) AS van, MAX(d.datum) AS tot,
                   COUNT(DISTINCT d.seizoen_id) AS seizoenen
            FROM (
                SELECT team1_speler1 AS speler_id, speeldag_id FROM intra_wedstrijden
                UNION ALL SELECT team1_speler2, speeldag_id FROM intra_wedstrijden
                UNION ALL SELECT team2_speler1, speeldag_id FROM intra_wedstrijden
                UNION ALL SELECT team2_speler2, speeldag_id FROM intra_wedstrijden
            ) s
            JOIN intra_speeldagen d ON d.id = s.speeldag_id
            GROUP BY s.speler_id
        '));
    }

    /** @return array<int, object> */
    private function spelerActiviteit(): array
    {
        return $this->perSpeler(DB::select('
            SELECT s.speler_id, COUNT(*) AS wedstrijden, MIN(r.date) AS van, MAX(r.date) AS tot
            FROM (
                SELECT player1_id AS speler_id, round_id FROM games
                UNION ALL SELECT player2_id, round_id FROM games
                UNION ALL SELECT player3_id, round_id FROM games
                UNION ALL SELECT player4_id, round_id FROM games
            ) s
            JOIN rounds r ON r.id = s.round_id
            GROUP BY s.speler_id
        '));
    }

    /**
     * @param  list<object>  $rijen
     * @return array<int, object>
     */
    private function perSpeler(array $rijen): array
    {
        $resultaat = [];
        foreach ($rijen as $rij) {
            $resultaat[$rij->speler_id] = $rij;
        }

        return $resultaat;
    }

    /** @param list<mixed> $velden */
    private function schrijfRij($bestand, array $velden): void
    {
        fputcsv($bestand, array_map(fn ($veld): string => (string) ($veld ?? ''), $velden), ';', '"', '\\');
    }
}
