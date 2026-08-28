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
            ->assertJsonPath('data.0.name', '2013 - 2014')
            ->assertJsonPath('data.0.source', 'intra')
            ->assertJsonPath('data.0.rounds_count', 1)
            ->assertJsonPath('data.0.players_count', 2);
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

    public function test_een_wedstrijd_toont_beide_teams_en_enkel_de_gespeelde_sets(): void
    {
        $this->getJson('/api/archive/rounds/1')
            ->assertOk()
            ->assertJsonPath('data.season_name', '2013 - 2014')
            ->assertJsonCount(1, 'data.games')
            ->assertJsonCount(2, 'data.games.0.team1')
            ->assertJsonPath('data.games.0.team1.0.last_name', 'Bollaert')
            // De derde set werd niet gespeeld en hoort er dus niet bij te staan.
            ->assertJsonCount(2, 'data.games.0.sets')
            ->assertJsonPath('data.games.0.sets_won', ['team1' => 2, 'team2' => 0]);
    }

    public function test_spelers_zijn_op_huidig_lid_te_filteren(): void
    {
        $this->getJson('/api/archive/players?player_id=7')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.last_name', 'Bollaert');

        $this->getJson('/api/archive/players?player_id=999')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_een_speler_toont_zijn_seizoenen_en_klassementsverloop(): void
    {
        $this->getJson('/api/archive/players/1')
            ->assertOk()
            ->assertJsonPath('data.player_id', 7)
            ->assertJsonCount(1, 'data.seasons')
            ->assertJsonPath('data.seasons.0.season_name', '2013 - 2014')
            ->assertJsonPath('data.seasons.0.games.won', 1)
            ->assertJsonCount(1, 'data.ranking_history')
            ->assertJsonPath('data.ranking_history.0.average', 19.5)
            ->assertJsonPath('data.ranking_history.0.date', '2013-10-02')
            // Wedstrijden staan er enkel op aanvraag: de lijst kan lang zijn.
            ->assertJsonMissingPath('data.games');

        $this->getJson('/api/archive/players/1?include=games')
            ->assertOk()
            ->assertJsonCount(1, 'data.games');
    }

    public function test_een_onbekende_include_geeft_422(): void
    {
        $this->getJson('/api/archive/players/1?include=onzin')->assertStatus(422);
    }

    private function seedArchief(): void
    {
        DB::table('players')->insert([
            'id' => 7, 'first_name' => 'Jan', 'last_name' => 'Bollaert', 'gender' => 'male',
            'birth_date' => '1980-01-01', 'double_ranking' => 5, 'plays_competition' => false, 'is_member' => true,
        ]);

        DB::table('archive_players')->insert([
            ['id' => 1, 'player_id' => 7, 'first_name' => 'Jan', 'last_name' => 'Bollaert', 'gender' => 'Man', 'ranking' => 'C2'],
            ['id' => 2, 'player_id' => null, 'first_name' => 'Piet', 'last_name' => 'Janssens', 'gender' => 'Man', 'ranking' => 'D'],
            ['id' => 3, 'player_id' => null, 'first_name' => 'Ann', 'last_name' => 'Peeters', 'gender' => 'Vrouw', 'ranking' => 'D'],
            ['id' => 4, 'player_id' => null, 'first_name' => 'Els', 'last_name' => 'Maes', 'gender' => 'Vrouw', 'ranking' => 'D'],
        ]);

        DB::table('archive_seasons')->insert([
            'id' => 1, 'name' => '2013 - 2014', 'source' => 'intra', 'source_id' => 1,
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
        DB::table('archive_player_round_statistics')->insert([
            ['archive_round_id' => 1, 'archive_player_id' => 1, 'average' => 19.5],
            ['archive_round_id' => 1, 'archive_player_id' => 2, 'average' => 17.25],
        ]);
    }
}
