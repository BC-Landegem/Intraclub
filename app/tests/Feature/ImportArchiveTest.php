<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Legt de vertaalregels vast waarmee de oude jaargangen in het archief belanden.
 * De archiefconnectie wijst hier naar een eigen sqlite-databank met een miniatuur
 * van het oude schema, zodat de test los staat van de productiedump.
 */
class ImportArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('database.connections.archive', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);

        $this->maakOudSchema();
    }

    public function test_het_zet_de_oude_generaties_om_naar_het_archief(): void
    {
        $this->gegevenEenHuidigeSpeler(1, 'Jan', 'Bollaert');
        $this->gegevenIntraData();
        $this->gegevenCompData();

        $this->artisan('intraclub:import-archive', ['--force' => true])->assertSuccessful();

        // Vier spelers per generatie, zonder overlappende namen.
        $this->assertSame(8, DB::table('archive_players')->count());
        $this->assertSame(2, DB::table('archive_seasons')->count());
        $this->assertSame(3, DB::table('archive_rounds')->count());
        $this->assertSame(3, DB::table('archive_games')->count());
        $this->assertSame(2, DB::table('archive_player_season_statistics')->count());
        $this->assertSame(1, DB::table('archive_player_round_statistics')->count());
    }

    public function test_het_koppelt_een_speler_die_nog_lid_is_aan_zijn_huidige_id(): void
    {
        $this->gegevenEenHuidigeSpeler(7, 'Jan', 'Bollaert');
        $this->gegevenIntraData();

        $this->artisan('intraclub:import-archive', ['--force' => true])->assertSuccessful();

        $this->assertSame(7, DB::table('archive_players')->where('intra_id', 1)->value('player_id'));
        // Wie enkel in het archief voorkomt heeft geen huidige speler.
        $this->assertNull(DB::table('archive_players')->where('intra_id', 2)->value('player_id'));
    }

    public function test_een_onbespeelde_derde_set_wordt_leeg_bewaard(): void
    {
        $this->gegevenIntraData();

        $this->artisan('intraclub:import-archive', ['--force' => true])->assertSuccessful();

        $beslistNaTweeSets = DB::table('archive_games')->where('source_id', 1)->first();
        $this->assertNull($beslistNaTweeSets->set3_home);
        $this->assertNull($beslistNaTweeSets->set3_away);

        $volledig = DB::table('archive_games')->where('source_id', 2)->first();
        $this->assertSame(21, (int) $volledig->set3_home);
        $this->assertSame(18, (int) $volledig->set3_away);
    }

    public function test_het_leidt_het_comp_seizoen_af_uit_de_datum(): void
    {
        $this->gegevenCompData();

        $this->artisan('intraclub:import-archive', ['--force' => true])->assertSuccessful();

        // Speeldagen in september en in mei daarna horen bij hetzelfde seizoen.
        $this->assertSame(['2009 - 2010'], DB::table('archive_seasons')->pluck('name')->all());
        $this->assertSame(2, DB::table('archive_rounds')->where('source', 'comp')->count());
    }

    public function test_het_slaat_wedstrijden_met_een_verwijderde_speler_over(): void
    {
        $this->gegevenCompData();
        DB::connection('archive')->table('comp_uitslagen')->insert([
            'ID' => 99, 'speeldag' => 1,
            'team1_speler1' => 1, 'team1_speler2' => 2, 'team2_speler1' => 3, 'team2_speler2' => 404,
            'set1_team1' => 21, 'set1_team2' => 10, 'set2_team1' => 21, 'set2_team2' => 12,
            'set3_team1' => 0, 'set3_team2' => 0,
        ]);

        $this->artisan('intraclub:import-archive', ['--force' => true])->assertSuccessful();

        $this->assertNull(DB::table('archive_games')->where('source_id', 99)->first());
    }

    public function test_het_weigert_te_draaien_zolang_een_koppeling_niet_beslist_is(): void
    {
        // Twee huidige spelers met dezelfde naam: welke van beide bedoeld is, kan het
        // matchen niet weten, dus moet de import stoppen in plaats van te gokken.
        $this->gegevenEenHuidigeSpeler(1, 'Jan', 'Bollaert');
        $this->gegevenEenHuidigeSpeler(2, 'Jan', 'Bollaert');
        $this->gegevenIntraData();

        $this->artisan('intraclub:import-archive', ['--force' => true])->assertFailed();

        $this->assertSame(0, DB::table('archive_players')->count());
    }

    // ------------------------------------------------------------ fixtures

    private function gegevenEenHuidigeSpeler(int $id, string $voornaam, string $naam): void
    {
        DB::table('players')->insert([
            'id' => $id,
            'first_name' => $voornaam,
            'last_name' => $naam,
            'gender' => 'male',
            'birth_date' => '1980-01-01',
            'double_ranking' => 5,
            'plays_competition' => false,
            'is_member' => true,
        ]);
    }

    private function gegevenIntraData(): void
    {
        $archive = DB::connection('archive');

        $archive->table('intra_spelers')->insert([
            ['id' => 1, 'voornaam' => 'Jan', 'naam' => 'Bollaert', 'geslacht' => 'Man', 'klassement' => 'C2', 'is_lid' => 1, 'is_veteraan' => 1],
            ['id' => 2, 'voornaam' => 'Piet', 'naam' => 'Janssens', 'geslacht' => 'Man', 'klassement' => 'D', 'is_lid' => 0, 'is_veteraan' => 0],
            ['id' => 3, 'voornaam' => 'Ann', 'naam' => 'Peeters', 'geslacht' => 'Vrouw', 'klassement' => 'Recreant', 'is_lid' => 1, 'is_veteraan' => 0],
            ['id' => 4, 'voornaam' => 'Els', 'naam' => 'Maes', 'geslacht' => 'Vrouw', 'klassement' => 'D', 'is_lid' => 1, 'is_veteraan' => 0],
        ]);
        $archive->table('intra_seizoen')->insert(['id' => 1, 'seizoen' => '2013 - 2014']);
        $archive->table('intra_speeldagen')->insert([
            'id' => 1, 'speeldagnummer' => 1, 'datum' => '2013-10-02', 'seizoen_id' => 1,
            'gemiddeld_verliezend' => 15.5, 'is_berekend' => 1,
        ]);
        $archive->table('intra_wedstrijden')->insert([
            [
                'id' => 1, 'speeldag_id' => 1,
                'team1_speler1' => 1, 'team1_speler2' => 2, 'team2_speler1' => 3, 'team2_speler2' => 4,
                'set1_1' => 21, 'set1_2' => 10, 'set2_1' => 21, 'set2_2' => 12, 'set3_1' => 0, 'set3_2' => 0,
            ],
            [
                'id' => 2, 'speeldag_id' => 1,
                'team1_speler1' => 1, 'team1_speler2' => 3, 'team2_speler1' => 2, 'team2_speler2' => 4,
                'set1_1' => 21, 'set1_2' => 15, 'set2_1' => 17, 'set2_2' => 21, 'set3_1' => 21, 'set3_2' => 18,
            ],
        ]);
        $archive->table('intra_spelerperseizoen')->insert([
            'id' => 1, 'speler_id' => 1, 'seizoen_id' => 1, 'basispunten' => 19.0,
            'gespeelde_sets' => 4, 'gewonnen_sets' => 3, 'gespeelde_punten' => 148, 'gewonnen_punten' => 80,
            'gespeelde_matchen' => 2, 'gewonnen_matchen' => 2, 'speeldagen_aanwezig' => 1,
        ]);
        $archive->table('intra_spelerperspeeldag')->insert([
            'speler_id' => 1, 'speeldag_id' => 1, 'gemiddelde' => 19.5,
        ]);
    }

    private function gegevenCompData(): void
    {
        $archive = DB::connection('archive');

        $archive->table('comp_spelers')->insert([
            ['ID' => 1, 'voornaam' => 'Marc', 'achternaam' => 'Willems', 'geslacht' => 'man'],
            ['ID' => 2, 'voornaam' => 'Rita', 'achternaam' => 'Claes', 'geslacht' => 'vrouw'],
            ['ID' => 3, 'voornaam' => 'Koen', 'achternaam' => 'Smets', 'geslacht' => 'man'],
            ['ID' => 4, 'voornaam' => 'Nele', 'achternaam' => 'Wouters', 'geslacht' => 'vrouw'],
        ]);
        $archive->table('comp_seizoen')->insert(['id' => 6, 'seizoen' => '2009 - 2010']);
        $archive->table('comp_dagen')->insert([
            ['ID' => 1, 'speeldag' => 1, 'datum' => '2009-09-30', 'gemiddelde_verliezers' => 14.5],
            ['ID' => 2, 'speeldag' => 16, 'datum' => '2010-05-12', 'gemiddelde_verliezers' => 14.0],
        ]);
        $archive->table('comp_uitslagen')->insert([
            'ID' => 1, 'speeldag' => 1,
            'team1_speler1' => 1, 'team1_speler2' => 2, 'team2_speler1' => 3, 'team2_speler2' => 4,
            'set1_team1' => 21, 'set1_team2' => 14, 'set2_team1' => 18, 'set2_team2' => 21,
            'set3_team1' => 21, 'set3_team2' => 19,
        ]);
        $archive->table('comp_historie')->insert([
            'ID' => 1, 'jeugd' => 0, 'punten' => 20.5, 'basispunten' => 19.0,
            'gespeelde_sets' => 3, 'gewonnen_sets' => 2, 'gespeelde_punten' => 114, 'gewonnen_punten' => 60,
            'gewonnen_spelletjes' => 1, 'aanwezig' => 1, 'speler_id' => 1, 'seizoen_id' => 6,
        ]);
    }

    /** Miniatuur van het oude schema: enkel de kolommen die de import gebruikt. */
    private function maakOudSchema(): void
    {
        $schema = Schema::connection('archive');

        $schema->create('intra_spelers', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('voornaam');
            $table->string('naam');
            $table->string('geslacht');
            $table->string('klassement');
            $table->boolean('is_lid');
            $table->boolean('is_veteraan');
        });
        $schema->create('intra_seizoen', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('seizoen');
        });
        $schema->create('intra_speeldagen', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('speeldagnummer');
            $table->date('datum');
            $table->integer('seizoen_id');
            $table->double('gemiddeld_verliezend')->nullable();
            $table->boolean('is_berekend');
        });
        $schema->create('intra_wedstrijden', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('speeldag_id');
            $table->integer('team1_speler1');
            $table->integer('team1_speler2');
            $table->integer('team2_speler1');
            $table->integer('team2_speler2');
            $table->integer('set1_1');
            $table->integer('set1_2');
            $table->integer('set2_1');
            $table->integer('set2_2');
            $table->integer('set3_1');
            $table->integer('set3_2');
        });
        $schema->create('intra_spelerperseizoen', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->integer('speler_id');
            $table->integer('seizoen_id');
            $table->double('basispunten');
            $table->integer('gespeelde_sets');
            $table->integer('gewonnen_sets');
            $table->integer('gespeelde_punten');
            $table->integer('gewonnen_punten');
            $table->integer('gespeelde_matchen');
            $table->integer('gewonnen_matchen');
            $table->integer('speeldagen_aanwezig');
        });
        $schema->create('intra_spelerperspeeldag', function (Blueprint $table) {
            $table->integer('speler_id');
            $table->integer('speeldag_id');
            $table->double('gemiddelde');
        });
        $schema->create('comp_spelers', function (Blueprint $table) {
            $table->integer('ID')->primary();
            $table->string('voornaam');
            $table->string('achternaam');
            $table->string('geslacht');
        });
        $schema->create('comp_seizoen', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('seizoen');
        });
        $schema->create('comp_dagen', function (Blueprint $table) {
            $table->integer('ID')->primary();
            $table->integer('speeldag');
            $table->date('datum');
            $table->double('gemiddelde_verliezers')->nullable();
        });
        $schema->create('comp_uitslagen', function (Blueprint $table) {
            $table->integer('ID')->primary();
            $table->integer('speeldag');
            $table->integer('team1_speler1');
            $table->integer('team1_speler2');
            $table->integer('team2_speler1');
            $table->integer('team2_speler2');
            $table->integer('set1_team1');
            $table->integer('set1_team2');
            $table->integer('set2_team1');
            $table->integer('set2_team2');
            $table->integer('set3_team1');
            $table->integer('set3_team2');
        });
        $schema->create('comp_historie', function (Blueprint $table) {
            $table->integer('ID')->primary();
            $table->boolean('jeugd');
            $table->double('punten')->nullable();
            $table->double('basispunten');
            $table->integer('gespeelde_sets');
            $table->integer('gewonnen_sets');
            $table->integer('gespeelde_punten');
            $table->integer('gewonnen_punten');
            $table->integer('gewonnen_spelletjes');
            $table->integer('aanwezig');
            $table->integer('speler_id');
            $table->integer('seizoen_id');
        });
    }
}
