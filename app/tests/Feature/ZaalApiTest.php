<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerRoundStatistic;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * De zaal-app: aanwezigheden, loting en score-invoer. Alles achter login.
 */
class ZaalApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Season $season;

    private Round $round;

    /** @var array<int, Player> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Zaalverantwoordelijke',
            'email' => 'zaal@bclandegem.be',
            'password' => Hash::make('geheim-wachtwoord'),
        ]);

        $this->season = Season::create(['name' => '2026 - 2027']);
        $this->round = $this->season->rounds()->create(['number' => 1, 'date' => '2026-09-01']);

        foreach (range(1, 6) as $index) {
            $player = Player::create([
                'first_name' => sprintf('Speler%02d', $index),
                'last_name' => 'Test',
                'gender' => 'male',
                'birth_date' => '1990-01-01',
                'double_ranking' => 100,
                'plays_competition' => true,
                'is_member' => true,
            ]);
            $this->players[$index] = $player;

            PlayerSeasonStatistic::create([
                'season_id' => $this->season->id,
                'player_id' => $player->id,
                'base_points' => 19 + $index / 100,
            ]);
        }
    }

    public function test_zaal_endpoints_vereisen_login(): void
    {
        $this->getJson('/api/zaal/round')->assertUnauthorized();
        $this->postJson("/api/zaal/rounds/{$this->round->id}/draw")->assertUnauthorized();
        $this->postJson("/api/zaal/rounds/{$this->round->id}/games", [])->assertUnauthorized();
    }

    public function test_inloggen_met_geldige_gegevens(): void
    {
        $this->postJson('/api/login', [
            'email' => 'zaal@bclandegem.be',
            'password' => 'geheim-wachtwoord',
        ])->assertOk()->assertJsonPath('email', 'zaal@bclandegem.be');

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_inloggen_met_verkeerd_wachtwoord_faalt(): void
    {
        $this->postJson('/api/login', [
            'email' => 'zaal@bclandegem.be',
            'password' => 'fout',
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_huidige_speeldag_toont_spelers_en_aanwezigheid(): void
    {
        $this->actingAs($this->user);

        $this->getJson('/api/zaal/round')
            ->assertOk()
            ->assertJsonPath('round.id', $this->round->id)
            ->assertJsonPath('presentCount', 0)
            ->assertJsonCount(6, 'players')
            ->assertJsonPath('players.0.present', false);
    }

    public function test_aanwezigheid_aanpassen(): void
    {
        $this->actingAs($this->user);

        $this->postJson("/api/zaal/rounds/{$this->round->id}/attendance", [
            'playerId' => $this->players[1]->id,
            'present' => true,
        ])->assertOk()->assertJsonPath('presentCount', 1);

        $this->assertTrue(
            PlayerRoundStatistic::where('round_id', $this->round->id)
                ->where('player_id', $this->players[1]->id)
                ->value('is_present')
        );
    }

    public function test_afwezig_zetten_wist_de_uitgeloot_vlag(): void
    {
        $this->actingAs($this->user);
        PlayerRoundStatistic::create([
            'round_id' => $this->round->id,
            'player_id' => $this->players[1]->id,
            'is_present' => true,
            'is_drawn_out' => true,
        ]);

        $this->postJson("/api/zaal/rounds/{$this->round->id}/attendance", [
            'playerId' => $this->players[1]->id,
            'present' => false,
        ])->assertOk();

        $statistic = PlayerRoundStatistic::where('round_id', $this->round->id)
            ->where('player_id', $this->players[1]->id)
            ->first();
        $this->assertFalse((bool) $statistic->is_present);
        $this->assertFalse((bool) $statistic->is_drawn_out);
    }

    public function test_loting_geeft_voorgestelde_matches_en_uitgelote_spelers(): void
    {
        $this->actingAs($this->user);
        $this->markPresent(range(1, 6));

        $response = $this->postJson("/api/zaal/rounds/{$this->round->id}/draw")
            ->assertOk()
            ->assertJsonCount(1, 'proposedGames')
            ->assertJsonCount(2, 'drawnOut');

        $this->assertCount(4, $response->json('proposedGames.0'));
        $this->assertArrayHasKey('fullName', $response->json('proposedGames.0.0'));
    }

    public function test_match_bevestigen_en_score_invoeren(): void
    {
        $this->actingAs($this->user);
        $this->markPresent(range(1, 4));

        $playerIds = collect(range(1, 4))->map(fn (int $i): int => $this->players[$i]->id)->all();

        $created = $this->postJson("/api/zaal/rounds/{$this->round->id}/games", ['playerIds' => $playerIds])
            ->assertOk()
            ->assertJsonCount(1, 'games');

        $gameId = $created->json('games.0.id');

        $this->putJson("/api/zaal/games/{$gameId}", [
            'set1_home' => 21, 'set1_away' => 15,
            'set2_home' => 21, 'set2_away' => 15,
            'set3_home' => 21, 'set3_away' => 15,
        ])->assertOk()->assertJsonPath('games.0.firstSet.home', 21);

        // Speeldag is compleet, dus de tussenstand is herberekend.
        $this->assertTrue($this->round->fresh()->is_calculated);
    }

    public function test_dezelfde_speler_mag_niet_twee_keer_in_een_match(): void
    {
        $this->actingAs($this->user);
        $id = $this->players[1]->id;

        $this->postJson("/api/zaal/rounds/{$this->round->id}/games", [
            'playerIds' => [$id, $id, $this->players[2]->id, $this->players[3]->id],
        ])->assertStatus(422);
    }

    public function test_een_aangemaakte_match_kan_niet_verwijderd_worden(): void
    {
        $this->actingAs($this->user);
        $playerIds = collect(range(1, 4))->map(fn (int $i): int => $this->players[$i]->id)->all();
        $created = $this->postJson("/api/zaal/rounds/{$this->round->id}/games", ['playerIds' => $playerIds]);
        $gameId = $created->json('games.0.id');

        // De zaal-app kent geen verwijder-actie; corrigeren gebeurt in het beheer.
        $this->deleteJson("/api/zaal/games/{$gameId}")->assertStatus(405);

        $this->assertDatabaseHas('games', ['id' => $gameId]);
    }

    public function test_nieuwe_speler_toevoegen_tijdens_de_speeldag(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson("/api/zaal/rounds/{$this->round->id}/players", [
            'firstName' => 'Nieuwe',
            'name' => 'Speler',
            'gender' => 'female',
            'birthDate' => '2000-05-04',
            'playsCompetition' => true,
            'doubleRanking' => 8,
        ])->assertCreated();

        // Hij staat meteen aanwezig en kan dus mee in de loting.
        $this->assertSame(7, count($response->json('players')));
        $this->assertSame(1, $response->json('presentCount'));

        $player = Player::where('first_name', 'Nieuwe')->firstOrFail();
        $this->assertTrue($player->is_member);
        $this->assertSame(
            19.0,
            (float) PlayerSeasonStatistic::where('player_id', $player->id)->value('base_points')
        );
    }

    public function test_nieuwe_speler_zonder_competitie_krijgt_geen_dubbelklassement(): void
    {
        $this->actingAs($this->user);

        $this->postJson("/api/zaal/rounds/{$this->round->id}/players", [
            'firstName' => 'Recreant',
            'name' => 'Speler',
            'gender' => 'male',
            'birthDate' => '1980-01-01',
            'playsCompetition' => false,
            'doubleRanking' => 7,
        ])->assertCreated();

        $player = Player::where('first_name', 'Recreant')->firstOrFail();
        $this->assertSame(0, $player->double_ranking);
        $this->assertFalse($player->plays_competition);
    }

    public function test_nieuwe_speler_met_ongeldige_gegevens_wordt_geweigerd(): void
    {
        $this->actingAs($this->user);

        $this->postJson("/api/zaal/rounds/{$this->round->id}/players", [
            'firstName' => '',
            'name' => 'Speler',
            'gender' => 'onbekend',
            'birthDate' => '2100-01-01',
            'playsCompetition' => true,
            'doubleRanking' => 99,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['firstName', 'gender', 'birthDate', 'doubleRanking']);
    }

    /** @param list<int> $playerIndexes */
    private function markPresent(array $playerIndexes): void
    {
        foreach ($playerIndexes as $index) {
            PlayerRoundStatistic::updateOrCreate(
                ['round_id' => $this->round->id, 'player_id' => $this->players[$index]->id],
                ['is_present' => true],
            );
        }
    }
}
