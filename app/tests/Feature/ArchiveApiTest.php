<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Contract van de archief-endpoints. Deze staan los van de publieke API van het
 * huidige format: het oude format kende vaste teams en soms maar twee sets, dus
 * een gearchiveerde wedstrijd ziet er anders uit dan een huidige.
 *
 * Van het archief blijft publiek enkel de eindstand over — elk seizoen erin is per
 * definitie afgesloten. Er zijn dus twee endpoints: de index om de standen te
 * vinden, en de stand zelf. Dat de speeldagen, de uitslagen en de spelersfiches
 * hier weg zijn, staat vastgelegd in HistoryScopeTest.
 *
 * De conventies zijn wel dezelfde: snake_case, `data` en `meta`.
 */
class ArchiveApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedArchief();
    }

    public function test_het_geeft_de_seizoenen_met_hun_omvang(): void
    {
        $this->getJson('/api/archive/seasons')
            ->assertOk()
            ->assertJsonPath('data.0.name', '2011 - 2012')
            ->assertJsonPath('data.0.source', 'comp')
            ->assertJsonPath('data.1.name', '2013 - 2014')
            ->assertJsonPath('data.1.source', 'intra')
            ->assertJsonPath('data.1.rounds_count', 1)
            // Drie seizoensrijen, twee spelers in de stand: `players_count` telt de
            // stand en niet de inschrijvingen. Anders stond er "142 spelers" boven
            // een tabel van 81 rijen zonder dat iets waarschuwde.
            ->assertJsonPath('data.1.players_count', 2);

        $this->assertSame(3, DB::table('archive_player_season_statistics')
            ->where('archive_season_id', 1)->count());
    }

    public function test_de_eindstand_staat_op_gemiddelde_gesorteerd(): void
    {
        $this->getJson('/api/archive/seasons/1/standings')
            ->assertOk()
            ->assertJsonPath('meta.season.name', '2013 - 2014')
            ->assertJsonPath('meta.after_round.number', 1)
            ->assertJsonPath('data.0.last_name', 'Bollaert')
            ->assertJsonPath('data.0.full_name', 'Jan Bollaert')
            ->assertJsonPath('data.0.average', 19.5)
            ->assertJsonPath('data.0.games.won', 1)
            ->assertJsonPath('data.1.last_name', 'Janssens')
            ->assertJsonPath('data.1.average', 17.25);
    }

    public function test_de_eindstand_verwijst_naar_het_huidige_lid(): void
    {
        $this->getJson('/api/archive/seasons/1/standings')
            ->assertOk()
            ->assertJsonPath('data.0.player_id', 7)
            // Wie gestopt is heeft geen huidige spelersfiche meer.
            ->assertJsonPath('data.1.player_id', null);
    }

    /**
     * Ann Peeters heeft een seizoensrij met basispunten maar geen enkele speeldagrij:
     * beide oude generaties stopten met speeldagrijen schrijven zodra iemand eruit
     * was, en dát is het spoor dat een uitschrijving achterliet. Ze stond toen ook
     * nergens.
     */
    public function test_zonder_eindgemiddelde_geen_plaats_in_de_eindstand(): void
    {
        $this->getJson('/api/archive/seasons/1/standings')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['last_name' => 'Peeters']);
    }

    /**
     * De comp-generatie hield geen klassementsevolutie bij, enkel een eindtotaal per
     * seizoen. Die vier seizoenen mogen dus niet leeglopen op de eis hierboven.
     */
    public function test_een_comp_seizoen_leunt_op_het_bewaarde_eindtotaal(): void
    {
        $this->getJson('/api/archive/seasons/2/standings')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.season.source', 'comp')
            ->assertJsonPath('data.0.last_name', 'Maes')
            ->assertJsonPath('data.0.average', 18.75);

        $this->getJson('/api/archive/seasons')
            ->assertOk()
            ->assertJsonPath('data.0.players_count', 2);
    }

    /**
     * Het geslacht staat in de stand zodat de site er het damesklassement van een
     * oude jaargang uit kan filteren — voor de huidige seizoenen doet
     * /rankings?category=women dat, en die route bestaat niet voor het archief.
     * Daarom dezelfde waarden als /players: één filter, geen tweede vocabulaire.
     */
    public function test_de_eindstand_geeft_het_geslacht_zoals_players_dat_doet(): void
    {
        $this->getJson('/api/archive/seasons/1/standings')
            ->assertOk()
            ->assertJsonPath('data.0.gender', 'male')
            ->assertJsonPath('data.1.gender', 'male');

        $this->getJson('/api/archive/seasons/2/standings')
            ->assertOk()
            ->assertJsonPath('data.0.gender', 'female')
            // De speler zonder persoon erachter. Null en niet weggelaten: elke rij in
            // `data` houdt dezelfde sleutels, en een damesfilter sluit hem vanzelf uit.
            ->assertJsonPath('data.1.last_name', 'Onbekende speler')
            ->assertJsonPath('data.1.gender', null);
    }

    /**
     * Naam en klassement in deze stand zijn "zoals toen", maar het geslacht niet: wie
     * nog een fiche heeft, krijgt wat daar staat. `players` wordt onderhouden, de
     * archieftabel wijzigt enkel bij een her-import — dus een correctie in het
     * beheerspaneel hoort meteen door te werken, ook op een stand van 2013.
     */
    public function test_het_geslacht_volgt_de_huidige_fiche_wanneer_die_bestaat(): void
    {
        DB::table('players')->where('id', 7)->update(['gender' => 'female']);

        $this->getJson('/api/archive/seasons/1/standings')
            ->assertOk()
            ->assertJsonPath('data.0.player_id', 7)
            ->assertJsonPath('data.0.gender', 'female')
            // Wie geen fiche meer heeft, blijft op het archief leunen.
            ->assertJsonPath('data.1.player_id', null)
            ->assertJsonPath('data.1.gender', 'male');
    }

    public function test_een_onbekende_include_geeft_422(): void
    {
        // Geen van beide overgebleven routes kent een include. Stil negeren bestaat
        // niet: elk endpoint zegt zelf wat het kent.
        $this->getJson('/api/archive/seasons?include=rounds')->assertStatus(422);
        $this->getJson('/api/archive/seasons/1/standings?include=games')->assertStatus(422);
    }

    private function seedArchief(): void
    {
        DB::table('players')->insert([
            'id' => 7, 'first_name' => 'Jan', 'last_name' => 'Bollaert', 'gender' => 'male',
            'birth_date' => '1980-01-01', 'double_ranking' => 5, 'plays_competition' => false, 'is_member' => true,
        ]);

        DB::table('archive_players')->insert([
            ['id' => 1, 'player_id' => 7, 'first_name' => 'Jan', 'last_name' => 'Bollaert', 'gender' => 'male', 'ranking' => 'C2'],
            ['id' => 2, 'player_id' => null, 'first_name' => 'Piet', 'last_name' => 'Janssens', 'gender' => 'male', 'ranking' => 'D'],
            ['id' => 3, 'player_id' => null, 'first_name' => 'Ann', 'last_name' => 'Peeters', 'gender' => 'female', 'ranking' => 'D'],
            ['id' => 4, 'player_id' => null, 'first_name' => 'Els', 'last_name' => 'Maes', 'gender' => 'female', 'ranking' => 'D'],
            // Een comp-speler die uit zijn eigen ledentabel verwijderd was: zijn
            // uitslagen zijn wél gespeeld, dus hij staat in de stand, maar er is geen
            // persoon meer om een geslacht aan te hangen. In het echte archief zijn er
            // acht van, verspreid over de drie oudste comp-seizoenen.
            ['id' => 5, 'player_id' => null, 'first_name' => '', 'last_name' => 'Onbekende speler', 'gender' => null, 'ranking' => null],
        ]);

        DB::table('archive_seasons')->insert([
            ['id' => 1, 'name' => '2013 - 2014', 'source' => 'intra', 'source_id' => 1],
            // De generatie zonder klassementsevolutie: enkel een eindtotaal.
            ['id' => 2, 'name' => '2011 - 2012', 'source' => 'comp', 'source_id' => 1],
        ]);
        DB::table('archive_rounds')->insert([
            'id' => 1, 'archive_season_id' => 1, 'number' => 1, 'date' => '2013-10-02',
            'average_absent' => 15.5, 'source' => 'intra', 'source_id' => 1,
        ]);
        DB::table('archive_games')->insert([
            'id' => 1, 'archive_round_id' => 1,
            'team1_player1_id' => 1, 'team1_player2_id' => 2, 'team2_player1_id' => 3, 'team2_player2_id' => 4,
            'set1_home' => 21, 'set1_away' => 10, 'set2_home' => 21, 'set2_away' => 12,
            'set3_home' => null, 'set3_away' => null, 'source' => 'intra', 'source_id' => 1,
        ]);
        DB::table('archive_player_season_statistics')->insert([
            ['archive_season_id' => 1, 'archive_player_id' => 1, 'base_points' => 19.0, 'sets_played' => 2, 'sets_won' => 2,
                'points_played' => 64, 'points_won' => 42, 'games_played' => 1, 'games_won' => 1, 'rounds_present' => 1],
            ['archive_season_id' => 1, 'archive_player_id' => 2, 'base_points' => 18.0, 'sets_played' => 2, 'sets_won' => 2,
                'points_played' => 64, 'points_won' => 42, 'games_played' => 1, 'games_won' => 1, 'rounds_present' => 1],
        ]);
        // Ingeschreven, nooit gespeeld: basispunten maar geen speeldagrij. Apart
        // ingevoegd omdat een bulk-insert overal dezelfde kolommen wil.
        DB::table('archive_player_season_statistics')->insert([
            'archive_season_id' => 1, 'archive_player_id' => 3, 'base_points' => 19.0,
        ]);
        DB::table('archive_player_season_statistics')->insert([
            ['archive_season_id' => 2, 'archive_player_id' => 4, 'base_points' => 19.0, 'final_points' => 18.75,
                'sets_played' => 2, 'sets_won' => 1, 'points_played' => 40, 'points_won' => 20,
                'games_played' => 1, 'games_won' => 0, 'rounds_present' => 1],
            ['archive_season_id' => 2, 'archive_player_id' => 5, 'base_points' => 19.0, 'final_points' => 17.0,
                'sets_played' => 2, 'sets_won' => 1, 'points_played' => 40, 'points_won' => 18,
                'games_played' => 1, 'games_won' => 0, 'rounds_present' => 1],
        ]);
        DB::table('archive_player_round_statistics')->insert([
            ['archive_round_id' => 1, 'archive_player_id' => 1, 'average' => 19.5],
            ['archive_round_id' => 1, 'archive_player_id' => 2, 'average' => 17.25],
        ]);
    }
}
