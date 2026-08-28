<?php

namespace App\Console\Commands;

use App\Services\Legacy\ArchiveCompStandings;
use App\Services\Legacy\ArchivePerson;
use App\Services\Legacy\ArchivePlayerMatcher;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importeert de intraclub-jaargangen van vóór het huidige systeem: `comp_*` (2009-2013)
 * en `intra_*` (2013-2023) uit de connectie "archive", naar de archive_-tabellen.
 *
 * Die tabellen staan bewust naast `games`: het oude format speelde met vaste teams in
 * best-of-3 (de derde set bleef leeg zodra een team er twee had), terwijl `games` vier
 * spelers met per set roterende teams veronderstelt. De opgeslagen statistieken worden
 * overgenomen zoals het oude systeem ze publiceerde — dit is een archief, geen
 * herberekening.
 *
 * Twee uitzonderingen, allebei omdat het oude systeem het zelf niet betrouwbaar
 * bijhield: de aanwezigheden en matchen ({@see berekenTellers}) en de eindstand van de
 * comp-generatie ({@see ArchiveCompStandings}). Beide worden uit de uitslagen afgeleid
 * en tegen de bewaarde cijfers afgezet, zodat elke afwijking in het rapport verschijnt.
 *
 * Volgorde: eerst `intraclub:load-archive-dump`, dan `intraclub:import-legacy`
 * (die vult `players`, waar de koppeling op steunt), dan dit commando.
 */
class ImportArchive extends Command
{
    protected $signature = 'intraclub:import-archive
        {--force : Sla de bevestiging over}';

    protected $description = 'Importeert de oude jaargangen (2009-2023) uit de connectie "archive" in de archief-tabellen.';

    private const TARGET_TABLES = [
        'archive_player_round_statistics',
        'archive_player_season_statistics',
        'archive_games',
        'archive_rounds',
        'archive_seasons',
        'archive_players',
    ];

    /** @var array<int, int> comp_spelers.ID => archive_players.id */
    private array $spelerPerCompId = [];

    /** @var array<int, int> intra_spelers.id => archive_players.id */
    private array $spelerPerIntraId = [];

    /** @var array<string, int> seizoensnaam => archive_seasons.id */
    private array $seizoenPerNaam = [];

    /** @var array<string, int> "bron:id" => archive_rounds.id */
    private array $speeldagPerBron = [];

    /** @var list<string> seizoensnamen van de comp-generatie, chronologisch */
    private array $compSeizoenen = [];

    /** @var array<int, string> comp_seizoen.id => seizoensnaam */
    private array $compNaamPerBronId = [];

    /** @var array<string, int> */
    private array $overgeslagen = [];

    /** @var array<string, true> reeds geschreven (seizoen, speler)-combinaties */
    private array $gezien = [];

    /** Aantal archiefspelers dat als "Onbekende speler" aangemaakt is. */
    private int $onbekendeSpelers = 0;

    public function handle(ArchivePlayerMatcher $matcher, ArchiveCompStandings $standings): int
    {
        $archive = DB::connection('archive');

        $personen = $matcher->persons();
        $tekort = array_filter($personen, fn (ArchivePerson $p): bool => $p->needsReview());

        if ($tekort !== []) {
            $this->error(sprintf('%d speler(s) hebben nog geen bevestigde koppeling:', count($tekort)));
            foreach ($tekort as $persoon) {
                $this->line(sprintf('  %-30s %s', $persoon->fullName(), implode(' | ', $persoon->notes)));
            }
            $this->newLine();
            $this->line('Kijk ze na met intraclub:player-map en leg de keuze vast in database/legacy/player-map-overrides.php.');

            return self::FAILURE;
        }

        foreach ($matcher->warnings() as $waarschuwing) {
            $this->warn("Override overgeslagen: {$waarschuwing}");
        }

        if (! $this->option('force') && ! $this->confirm(sprintf(
            'Dit wist alle data in [%s] van database "%s" en importeert opnieuw vanaf "%s". Doorgaan?',
            implode(', ', self::TARGET_TABLES),
            DB::getDatabaseName(),
            $archive->getDatabaseName(),
        ))) {
            return self::FAILURE;
        }

        Schema::withoutForeignKeyConstraints(function () use ($archive, $personen, $standings): void {
            foreach (self::TARGET_TABLES as $table) {
                DB::table($table)->truncate();
            }

            $this->importeerSpelers($personen);
            $this->importeerOnbekendeCompSpelers($archive);
            $this->importeerIntraSeizoenen($archive);
            $this->importeerIntraSpeeldagen($archive);
            $this->importeerIntraWedstrijden($archive);
            $this->importeerIntraSeizoenStatistieken($archive);
            $this->importeerIntraSpeeldagStatistieken($archive);
            $this->importeerCompSeizoenen($archive);
            $this->importeerCompSpeeldagen($archive);
            $this->importeerCompWedstrijden($archive);
            $this->importeerCompSeizoenStatistieken($archive);
            $this->berekenTellers();

            foreach ($standings->recalculate() as $reden => $aantal) {
                $this->overgeslagen[$reden] = ($this->overgeslagen[$reden] ?? 0) + $aantal;
            }
        });

        $this->rapporteer($personen);

        return self::SUCCESS;
    }

    /** @param list<ArchivePerson> $personen */
    private function importeerSpelers(array $personen): void
    {
        $nu = now();
        $rijen = [];

        foreach ($personen as $persoon) {
            $rijen[] = [
                'player_id' => $persoon->playerId,
                'first_name' => $persoon->firstName,
                'last_name' => $persoon->lastName,
                'gender' => $persoon->gender,
                'ranking' => $persoon->ranking === '' ? null : $persoon->ranking,
                'comp_id' => $persoon->comp?->ID,
                'intra_id' => $persoon->intra?->id,
                'created_at' => $nu,
                'updated_at' => $nu,
            ];
        }

        DB::table('archive_players')->insert($rijen);

        foreach (DB::table('archive_players')->select('id', 'comp_id', 'intra_id')->get() as $rij) {
            if ($rij->comp_id !== null) {
                $this->spelerPerCompId[$rij->comp_id] = $rij->id;
            }
            if ($rij->intra_id !== null) {
                $this->spelerPerIntraId[$rij->intra_id] = $rij->id;
            }
        }
    }

    /**
     * De comp-generatie verwijst naar spelers die later uit `comp_spelers` verwijderd zijn.
     * Hun uitslagen zijn wél echt gespeeld, dus die willen we niet weggooien: elk onbekend
     * bron-id krijgt een eigen archiefspeler "Onbekende speler".
     *
     * Eén per bron-id, niet één gedeelde: twee wedstrijden hebben méér dan één onbekende
     * speler, en die zouden anders als dezelfde persoon in dezelfde match belanden.
     */
    private function importeerOnbekendeCompSpelers(ConnectionInterface $archive): void
    {
        $gekend = $archive->table('comp_spelers')->pluck('ID')->all();

        $gebruikt = $archive->table(
            $archive->table('comp_uitslagen')->select('team1_speler1 as speler_id')
                ->unionAll($archive->table('comp_uitslagen')->select('team1_speler2'))
                ->unionAll($archive->table('comp_uitslagen')->select('team2_speler1'))
                ->unionAll($archive->table('comp_uitslagen')->select('team2_speler2'))
                ->unionAll($archive->table('comp_historie')->select('speler_id')),
            'gebruikt'
        )->distinct()->pluck('speler_id');

        $onbekend = $gebruikt->reject(fn ($id): bool => in_array($id, $gekend))->sort()->values();

        foreach ($onbekend as $compId) {
            $this->spelerPerCompId[$compId] = DB::table('archive_players')->insertGetId([
                'player_id' => null,
                'first_name' => '',
                'last_name' => 'Onbekende speler',
                'gender' => null,
                'ranking' => null,
                'comp_id' => $compId,
                'intra_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($onbekend->isNotEmpty()) {
            $this->onbekendeSpelers = $onbekend->count();
        }
    }

    private function importeerIntraSeizoenen(ConnectionInterface $archive): void
    {
        foreach ($archive->table('intra_seizoen')->orderBy('id')->get() as $rij) {
            $this->seizoenPerNaam[$rij->seizoen] = DB::table('archive_seasons')->insertGetId([
                'name' => $rij->seizoen,
                'source' => 'intra',
                'source_id' => $rij->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function importeerIntraSpeeldagen(ConnectionInterface $archive): void
    {
        $seizoenNaamPerId = $archive->table('intra_seizoen')->pluck('seizoen', 'id');

        foreach ($archive->table('intra_speeldagen')->orderBy('id')->get() as $rij) {
            $naam = $seizoenNaamPerId[$rij->seizoen_id] ?? null;
            if ($naam === null) {
                $this->tel('speeldagen zonder seizoen');

                continue;
            }

            $this->speeldagPerBron["intra:{$rij->id}"] = DB::table('archive_rounds')->insertGetId([
                'archive_season_id' => $this->seizoenPerNaam[$naam],
                'number' => $rij->speeldagnummer,
                'date' => $rij->datum,
                'average_absent' => $rij->gemiddeld_verliezend,
                'source' => 'intra',
                'source_id' => $rij->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function importeerIntraWedstrijden(ConnectionInterface $archive): void
    {
        $rijen = [];

        foreach ($archive->table('intra_wedstrijden')->orderBy('id')->get() as $rij) {
            $speeldag = $this->speeldagPerBron["intra:{$rij->speeldag_id}"] ?? null;
            $spelers = $this->spelersVan([
                $rij->team1_speler1, $rij->team1_speler2, $rij->team2_speler1, $rij->team2_speler2,
            ], $this->spelerPerIntraId);

            if ($speeldag === null || $spelers === null) {
                $this->tel('intra-wedstrijden overgeslagen');

                continue;
            }

            // De derde set werd niet gespeeld zodra een team er twee had.
            $derdeGespeeld = $rij->set3_1 !== 0 || $rij->set3_2 !== 0;
            $this->controleerSetstanden([$rij->set1_1, $rij->set1_2, $rij->set2_1, $rij->set2_2]);

            $rijen[] = [
                'archive_round_id' => $speeldag,
                'team1_player1_id' => $spelers[0],
                'team1_player2_id' => $spelers[1],
                'team2_player1_id' => $spelers[2],
                'team2_player2_id' => $spelers[3],
                'set1_home' => $rij->set1_1,
                'set1_away' => $rij->set1_2,
                'set2_home' => $rij->set2_1,
                'set2_away' => $rij->set2_2,
                'set3_home' => $derdeGespeeld ? $rij->set3_1 : null,
                'set3_away' => $derdeGespeeld ? $rij->set3_2 : null,
                'source' => 'intra',
                'source_id' => $rij->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->schrijf('archive_games', $rijen);
    }

    private function importeerIntraSeizoenStatistieken(ConnectionInterface $archive): void
    {
        $seizoenNaamPerId = $archive->table('intra_seizoen')->pluck('seizoen', 'id');
        $rijen = [];

        foreach ($archive->table('intra_spelerperseizoen')->orderBy('id')->get() as $rij) {
            $speler = $this->spelerPerIntraId[$rij->speler_id] ?? null;
            $naam = $seizoenNaamPerId[$rij->seizoen_id] ?? null;

            if ($speler === null || $naam === null) {
                $this->tel('intra-seizoenstatistieken overgeslagen');

                continue;
            }
            if (! $this->nogNietGezien("{$this->seizoenPerNaam[$naam]}:{$speler}", 'dubbele seizoenstatistieken overgeslagen')) {
                continue;
            }

            $rijen[] = [
                'archive_season_id' => $this->seizoenPerNaam[$naam],
                'archive_player_id' => $speler,
                'base_points' => $rij->basispunten,
                'final_points' => null,
                'sets_played' => $rij->gespeelde_sets,
                'sets_won' => $rij->gewonnen_sets,
                'points_played' => $rij->gespeelde_punten,
                'points_won' => $rij->gewonnen_punten,
                // Deze drie worden na de import uit de uitslagen herrekend; de
                // bewaarde aanwezigheid dient nog als controle (zie berekenTellers).
                'games_played' => 0,
                'games_won' => 0,
                'rounds_present' => $rij->speeldagen_aanwezig,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->schrijf('archive_player_season_statistics', $rijen);
    }

    private function importeerIntraSpeeldagStatistieken(ConnectionInterface $archive): void
    {
        $rijen = [];

        foreach ($archive->table('intra_spelerperspeeldag')->orderBy('speeldag_id')->cursor() as $rij) {
            $speler = $this->spelerPerIntraId[$rij->speler_id] ?? null;
            $speeldag = $this->speeldagPerBron["intra:{$rij->speeldag_id}"] ?? null;

            if ($speler === null || $speeldag === null) {
                $this->tel('intra-speeldagstatistieken overgeslagen');

                continue;
            }

            $rijen[] = [
                'archive_round_id' => $speeldag,
                'archive_player_id' => $speler,
                'average' => $rij->gemiddelde,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->schrijf('archive_player_round_statistics', $rijen);
    }

    /**
     * Telt gespeelde speeldagen en matchen uit de uitslagen, voor beide generaties.
     *
     * Geen van beide oude systemen hield dit betrouwbaar bij: `intra_*` begon er pas
     * in 2019-2020 mee (daarvóór staat overal nul) en `comp_*` telde enkel iets dat
     * "gewonnen spelletjes" heette en niet met gewonnen matchen overeenkomt. Uit de
     * uitslagen volgt één definitie voor alle veertien seizoenen — en waar `intra_*`
     * het wél bijhield, komt die berekening exact op dezelfde cijfers uit. Dat wordt
     * hier ook gecontroleerd en gerapporteerd.
     */
    private function berekenTellers(): void
    {
        $tellers = [];

        $games = DB::table('archive_games as g')
            ->join('archive_rounds as r', 'r.id', '=', 'g.archive_round_id')
            ->select('g.*', 'r.archive_season_id')
            ->orderBy('g.id')
            ->get();

        foreach ($games as $game) {
            [$team1, $team2] = $this->setsGewonnen($game);

            foreach ([1 => [$game->team1_player1_id, $game->team1_player2_id], 2 => [$game->team2_player1_id, $game->team2_player2_id]] as $team => $spelers) {
                $gewonnen = $team === 1 ? $team1 > $team2 : $team2 > $team1;

                foreach ($spelers as $spelerId) {
                    $sleutel = "{$game->archive_season_id}:{$spelerId}";
                    $tellers[$sleutel] ??= ['dagen' => [], 'matchen' => 0, 'gewonnen' => 0];
                    $tellers[$sleutel]['dagen'][$game->archive_round_id] = true;
                    $tellers[$sleutel]['matchen']++;
                    $tellers[$sleutel]['gewonnen'] += $gewonnen ? 1 : 0;
                }
            }
        }

        $rijen = DB::table('archive_player_season_statistics as stat')
            ->join('archive_seasons as seizoen', 'seizoen.id', '=', 'stat.archive_season_id')
            ->select('stat.*', 'seizoen.source')
            ->get();

        foreach ($rijen as $rij) {
            $teller = $tellers["{$rij->archive_season_id}:{$rij->archive_player_id}"]
                ?? ['dagen' => [], 'matchen' => 0, 'gewonnen' => 0];

            $dagen = count($teller['dagen']);

            // Enkel intra_* telde aanwezigheden op dezelfde manier, en enkel vanaf
            // 2019-2020. Wijkt daar iets af, dan klopt onze afleiding niet.
            //
            // Voor comp_* is dit meteen de controle op de seizoenskoppeling: die
            // generatie draagt geen bruikbaar seizoenslabel, dus zit de koppeling op
            // volgorde ernaast, dan slaan de bewaarde aanwezigheden op een ander jaar
            // en loopt deze teller meteen vol.
            $bewaardeTelling = $rij->source === 'comp' || $rij->rounds_present > 0;

            if ($bewaardeTelling && (int) $rij->rounds_present !== $dagen) {
                $this->tel('bewaarde aanwezigheid wijkt af van de uitslagen');
            }

            // De comp-generatie bewaarde eindstanden voor spelers van wie geen enkele
            // uitslag overgeleverd is. Die staan dus op nul speeldagen terwijl ze wel
            // sets en punten hebben — een gat in de oude data, niet in de import.
            if ($teller['matchen'] === 0 && $rij->sets_played > 0) {
                $this->tel('seizoenstatistieken zonder bijhorende uitslagen');
            }

            DB::table('archive_player_season_statistics')->where('id', $rij->id)->update([
                'rounds_present' => $dagen,
                'games_played' => $teller['matchen'],
                'games_won' => $teller['gewonnen'],
            ]);
        }
    }

    /**
     * Gewonnen sets per team. Een onbespeelde derde set telt voor geen van beide.
     *
     * @return array{0: int, 1: int}
     */
    private function setsGewonnen(object $game): array
    {
        $team1 = 0;
        $team2 = 0;

        foreach ([['set1_home', 'set1_away'], ['set2_home', 'set2_away'], ['set3_home', 'set3_away']] as [$thuis, $uit]) {
            if ($game->{$thuis} === null || $game->{$uit} === null) {
                continue;
            }
            if ($game->{$thuis} > $game->{$uit}) {
                $team1++;
            } elseif ($game->{$thuis} < $game->{$uit}) {
                $team2++;
            }
        }

        return [$team1, $team2];
    }

    /**
     * De comp-generatie hield geen seizoen bij op de speeldag, dus leiden we het af uit
     * de datum: vanaf augustus start een nieuw seizoen. Dat geeft vier seizoenen,
     * 2009-2010 t/m 2012-2013.
     *
     * De labels in `comp_seizoen` zijn géén bruikbare bron: die tabel telt maar drie
     * rijen (id 6, 8 en 9) en de namen erop staan één seizoen te ver. Dat is niet af te
     * leiden uit de tabel zelf maar wel uit de cijfers: koppel je `comp_historie` op
     * naam, dan wijken gespeelde sets en aanwezigheden van élke speler af, terwijl ze
     * op volgorde voor alle drie de seizoenen exact kloppen (52/52, 58/58 en 61/61).
     * De rij met id 8 draagt dus de stand van 2010-2011, niet van 2011-2012.
     *
     * We koppelen daarom op volgorde en negeren de opgeslagen naam. Het overblijvende
     * seizoen — het laatste — heeft geen rij in `comp_historie`; zie
     * {@see importeerCompSeizoenStatistieken}.
     */
    private function importeerCompSeizoenen(ConnectionInterface $archive): void
    {
        $namen = [];
        foreach ($archive->table('comp_dagen')->orderBy('datum')->get() as $rij) {
            $namen[$this->seizoenVanDatum($rij->datum)] = true;
        }
        $this->compSeizoenen = array_keys($namen);

        $bronIds = $archive->table('comp_seizoen')->orderBy('id')->pluck('id')->all();

        if (count($bronIds) > count($this->compSeizoenen)) {
            $this->tel('comp_seizoen telt meer rijen dan er seizoenen zijn');
        }

        foreach ($this->compSeizoenen as $index => $naam) {
            $bronId = $bronIds[$index] ?? null;

            if ($bronId !== null) {
                $this->compNaamPerBronId[$bronId] = $naam;
            }

            $this->seizoenPerNaam[$naam] = DB::table('archive_seasons')->insertGetId([
                'name' => $naam,
                'source' => 'comp',
                'source_id' => $bronId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function importeerCompSpeeldagen(ConnectionInterface $archive): void
    {
        foreach ($archive->table('comp_dagen')->orderBy('datum')->get() as $rij) {
            $naam = $this->seizoenVanDatum($rij->datum);

            $this->speeldagPerBron["comp:{$rij->ID}"] = DB::table('archive_rounds')->insertGetId([
                'archive_season_id' => $this->seizoenPerNaam[$naam],
                'number' => $rij->speeldag,
                'date' => $rij->datum,
                'average_absent' => $rij->gemiddelde_verliezers,
                'source' => 'comp',
                'source_id' => $rij->ID,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function importeerCompWedstrijden(ConnectionInterface $archive): void
    {
        $rijen = [];

        foreach ($archive->table('comp_uitslagen')->orderBy('ID')->get() as $rij) {
            $speeldag = $this->speeldagPerBron["comp:{$rij->speeldag}"] ?? null;
            $spelers = $this->spelersVan([
                $rij->team1_speler1, $rij->team1_speler2, $rij->team2_speler1, $rij->team2_speler2,
            ], $this->spelerPerCompId);

            if ($speeldag === null || $spelers === null) {
                $this->tel('comp-wedstrijden overgeslagen');

                continue;
            }

            $derdeGespeeld = $rij->set3_team1 !== 0 || $rij->set3_team2 !== 0;
            $this->controleerSetstanden([$rij->set1_team1, $rij->set1_team2, $rij->set2_team1, $rij->set2_team2]);

            $rijen[] = [
                'archive_round_id' => $speeldag,
                'team1_player1_id' => $spelers[0],
                'team1_player2_id' => $spelers[1],
                'team2_player1_id' => $spelers[2],
                'team2_player2_id' => $spelers[3],
                'set1_home' => $rij->set1_team1,
                'set1_away' => $rij->set1_team2,
                'set2_home' => $rij->set2_team1,
                'set2_away' => $rij->set2_team2,
                'set3_home' => $derdeGespeeld ? $rij->set3_team1 : null,
                'set3_away' => $derdeGespeeld ? $rij->set3_team2 : null,
                'source' => 'comp',
                'source_id' => $rij->ID,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->schrijf('archive_games', $rijen);
    }

    /**
     * De seizoensstatistieken van de comp-generatie komen uit twee tabellen.
     *
     * `comp_historie` is het archief dat het oude systeem aanlegde bij de start van een
     * nieuw seizoen — vandaar dat het laatste seizoen er niet in staat: daarna is er
     * nooit nog een seizoen begonnen. Voor dat laatste seizoen staat de stand nog in
     * `comp_spelers`, de tabel waarin het systeem de lopende stand bijhield. Dat die
     * rijen wel degelijk het laatste seizoen beschrijven is na te rekenen: hun tellers
     * komen exact overeen met de uitslagen van dat jaar, en hun `basispunten` zijn tot
     * op de laatste decimaal de eindpunten van het seizoen ervoor.
     */
    private function importeerCompSeizoenStatistieken(ConnectionInterface $archive): void
    {
        $rijen = [];

        foreach ($archive->table('comp_historie')->orderBy('ID')->get() as $rij) {
            $naam = $this->compNaamPerBronId[$rij->seizoen_id] ?? null;
            $rij = $this->compSeizoenStatistiek($naam, (int) $rij->speler_id, $rij);

            if ($rij !== null) {
                $rijen[] = $rij;
            }
        }

        $laatste = $this->compSeizoenen === [] ? null : end($this->compSeizoenen);

        if ($laatste !== null && ! in_array($laatste, $this->compNaamPerBronId, true)) {
            foreach ($archive->table('comp_spelers')->orderBy('ID')->get() as $rij) {
                $rij = $this->compSeizoenStatistiek($laatste, (int) $rij->ID, $rij);

                if ($rij !== null) {
                    $rijen[] = $rij;
                }
            }
        }

        $this->schrijf('archive_player_season_statistics', $rijen);
    }

    /**
     * Eén seizoensstatistiek uit `comp_historie` of `comp_spelers`; beide dragen
     * dezelfde kolommen. null zodra de speler of het seizoen niet te plaatsen is.
     *
     * @return array<string, mixed>|null
     */
    private function compSeizoenStatistiek(?string $seizoensnaam, int $compSpelerId, object $bron): ?array
    {
        $speler = $this->spelerPerCompId[$compSpelerId] ?? null;
        $seizoen = $seizoensnaam === null ? null : ($this->seizoenPerNaam[$seizoensnaam] ?? null);

        if ($speler === null || $seizoen === null) {
            $this->tel('comp-seizoenstatistieken overgeslagen');

            return null;
        }
        if (! $this->nogNietGezien("{$seizoen}:{$speler}", 'dubbele seizoenstatistieken overgeslagen')) {
            return null;
        }

        return [
            'archive_season_id' => $seizoen,
            'archive_player_id' => $speler,
            'base_points' => $bron->basispunten,
            // De stand zoals het oude systeem ze publiceerde. ArchiveCompStandings
            // rekent ze na de import opnieuw uit en meldt elk verschil.
            'final_points' => $bron->punten,
            'sets_played' => $bron->gespeelde_sets,
            'sets_won' => $bron->gewonnen_sets,
            'points_played' => $bron->gespeelde_punten,
            'points_won' => $bron->gewonnen_punten,
            // Deze drie worden na de import uit de uitslagen herrekend. De comp-
            // generatie telde "gewonnen spelletjes", wat niet met gewonnen matchen
            // overeenkomt, dus die waarde nemen we niet over.
            'games_played' => 0,
            'games_won' => 0,
            'rounds_present' => $bron->aanwezig,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    // ------------------------------------------------------------ hulp

    /**
     * @param  list<int>  $bronIds
     * @param  array<int, int>  $vertaling
     * @return list<int>|null null zodra één speler onbekend is
     */
    private function spelersVan(array $bronIds, array $vertaling): ?array
    {
        $spelers = [];
        foreach ($bronIds as $bronId) {
            if (! isset($vertaling[$bronId])) {
                return null;
            }
            $spelers[] = $vertaling[$bronId];
        }

        return $spelers;
    }

    /**
     * Signaleert setstanden die niet kunnen kloppen. Ze worden wél geïmporteerd — het
     * archief bewaart wat het oude systeem toonde — maar je wil weten dat ze er zijn.
     *
     * @param  list<int|null>  $scores
     */
    private function controleerSetstanden(array $scores): void
    {
        foreach ($scores as $score) {
            if ($score !== null && ($score < 0 || $score > 40)) {
                $this->tel('wedstrijden met een onmogelijke setstand');

                return;
            }
        }
    }

    /** Een seizoen loopt van augustus tot juni: "2009 - 2010". */
    private function seizoenVanDatum(string $datum): string
    {
        $jaar = (int) substr($datum, 0, 4);
        $maand = (int) substr($datum, 5, 2);
        $start = $maand >= 8 ? $jaar : $jaar - 1;

        return sprintf('%d - %d', $start, $start + 1);
    }

    /** @param list<array<string, mixed>> $rijen */
    private function schrijf(string $tabel, array $rijen): void
    {
        foreach (array_chunk($rijen, 500) as $blok) {
            DB::table($tabel)->insert($blok);
        }
    }

    private function tel(string $reden): void
    {
        $this->overgeslagen[$reden] = ($this->overgeslagen[$reden] ?? 0) + 1;
    }

    /**
     * De oude tabellen hebben geen unieke sleutel op (seizoen, speler), en bevatten
     * daardoor een enkele dubbel ingevoerde rij. De eerste wint.
     */
    private function nogNietGezien(string $sleutel, string $reden): bool
    {
        if (isset($this->gezien[$sleutel])) {
            $this->tel($reden);

            return false;
        }
        $this->gezien[$sleutel] = true;

        return true;
    }

    /** @param list<ArchivePerson> $personen */
    private function rapporteer(array $personen): void
    {
        $this->newLine();
        foreach (array_reverse(self::TARGET_TABLES) as $table) {
            $this->info(sprintf('%-35s %6d rijen', $table, DB::table($table)->count()));
        }

        $gekoppeld = count(array_filter($personen, fn (ArchivePerson $p): bool => $p->playerId !== null));
        $this->newLine();
        $this->line(sprintf(
            '%d spelers, waarvan %d gekoppeld aan een huidig lid en %d enkel in het archief.',
            count($personen),
            $gekoppeld,
            count($personen) - $gekoppeld,
        ));

        if ($this->onbekendeSpelers > 0) {
            $this->line(sprintf(
                'Daarnaast %d× "Onbekende speler" aangemaakt voor comp-id\'s die uit het oude ledenbestand verdwenen zijn.',
                $this->onbekendeSpelers,
            ));
        }

        foreach ($this->overgeslagen as $reden => $aantal) {
            $this->warn(sprintf('%s: %d', $reden, $aantal));
        }
    }
}
