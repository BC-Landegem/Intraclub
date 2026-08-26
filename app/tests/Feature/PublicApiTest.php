<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerRoundStatistic;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Legt het publieke API-contract vast: de clubwebsite consumeert deze vorm,
 * die identiek moet blijven aan de legacy-API.
 */
class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    private Season $season;

    private Round $round;

    /** @var array<int, Player> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->season = Season::create(['name' => '2026 - 2027']);
        $this->round = $this->season->rounds()->create(['number' => 1, 'date' => '2026-09-01']);

        foreach (range(1, 4) as $index) {
            $player = Player::create([
                'first_name' => "Speler{$index}",
                'last_name' => 'Test',
                'gender' => $index === 1 ? 'female' : 'male',
                'birth_date' => $index === 2 ? '1970-01-01' : '1995-01-01',
                'double_ranking' => 100,
                'plays_competition' => $index !== 3,
                'is_member' => true,
            ]);
            $this->players[$index] = $player;

            PlayerSeasonStatistic::create([
                'season_id' => $this->season->id,
                'player_id' => $player->id,
                'base_points' => 19 + $index / 10,
            ]);
        }

        Game::create([
            'round_id' => $this->round->id,
            'player1_id' => $this->players[1]->id,
            'player2_id' => $this->players[2]->id,
            'player3_id' => $this->players[3]->id,
            'player4_id' => $this->players[4]->id,
            'set1_home' => 21, 'set1_away' => 15,
            'set2_home' => 21, 'set2_away' => 15,
            'set3_home' => 21, 'set3_away' => 15,
        ]);
    }

    public function test_klassement_bevat_de_vier_categorieen(): void
    {
        $this->getJson('/api/rankings')
            ->assertOk()
            ->assertJsonStructure([
                'seasonId',
                'general' => [['id', 'firstName', 'name', 'average', 'rank', 'difference']],
                'women',
                'veterans',
                'recreants',
            ]);
    }

    public function test_klassementcategorie_kan_apart_opgevraagd_worden(): void
    {
        $response = $this->getJson('/api/rankings/women')->assertOk();

        $this->assertSame([$this->players[1]->id], array_column($response->json('women'), 'id'));
        $this->assertNull($response->json('general'));
    }

    public function test_onbekende_klassementcategorie_geeft_404(): void
    {
        $this->getJson('/api/rankings/onzin')->assertNotFound();
    }

    public function test_speeldagen_zijn_een_kale_lijst_met_aantal_games(): void
    {
        $this->getJson('/api/rounds')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.number', 1)
            ->assertJsonPath('0.matches', 1)
            ->assertJsonPath('0.date', '2026-09-01');
    }

    public function test_speeldagdetail_bevat_wedstrijden_en_aanwezigheden(): void
    {
        $this->getJson("/api/rounds/{$this->round->id}")
            ->assertOk()
            ->assertJsonPath('matches.0.firstPlayer.firstName', 'Speler1')
            ->assertJsonPath('matches.0.firstSet.home', 21)
            ->assertJsonPath('matches.0.round.number', 1)
            ->assertJsonStructure(['id', 'number', 'averageAbsent', 'date', 'matches', 'availabilityData']);
    }

    public function test_laatst_berekende_speeldag(): void
    {
        $this->getJson('/api/rounds/latestCalculated')
            ->assertOk()
            ->assertJsonPath('id', $this->round->id)
            ->assertJsonPath('calculated', 1);
    }

    public function test_spelerslijst_gebruikt_de_legacy_geslachtswaarden(): void
    {
        $response = $this->getJson('/api/players')->assertOk();

        $this->assertSame('Woman', $response->json('0.gender'));
        $this->assertSame('Man', $response->json('1.gender'));
    }

    public function test_spelerdetail_bevat_statistieken_en_rankinggeschiedenis(): void
    {
        $this->getJson("/api/players/{$this->players[1]->id}")
            ->assertOk()
            ->assertJsonPath('firstName', 'Speler1')
            ->assertJsonStructure([
                'id', 'firstName', 'name',
                'statistics' => [
                    'points' => ['won', 'lost', 'total'],
                    'sets' => ['won', 'lost', 'total'],
                    'matches' => ['total'],
                    'rounds' => ['present'],
                    'rankingHistory' => [['id', 'number', 'average', 'rank']],
                ],
                'matches',
            ]);
    }

    public function test_seizoensstatistieken_staan_op_aanwezigheid_gesorteerd(): void
    {
        PlayerRoundStatistic::where('player_id', $this->players[4]->id)->delete();
        PlayerSeasonStatistic::where('player_id', $this->players[1]->id)->update(['rounds_present' => 99]);

        $response = $this->getJson('/api/seasons/latest/statistics')->assertOk();

        $this->assertSame($this->players[1]->id, $response->json('0.id'));
    }

    public function test_publieke_api_vereist_geen_login(): void
    {
        $this->assertGuest();
        $this->getJson('/api/rankings')->assertOk();
        $this->getJson('/api/players')->assertOk();
    }
}
