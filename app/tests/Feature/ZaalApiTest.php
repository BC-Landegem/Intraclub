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
use Tests\Concerns\PlaysToPoints;
use Tests\TestCase;

/**
 * De zaal-app: aanwezigheden, loting en score-invoer. Alles achter login.
 *
 * Draait voor sets tot 15 en tot 21: zie {@see ZaalApiPlayedTo21Test}.
 */
class ZaalApiTest extends TestCase
{
    use PlaysToPoints;
    use RefreshDatabase;

    private User $user;

    private Season $season;

    private Round $round;

    /** @var array<int, Player> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootFormat();

        $this->user = User::create([
            'name' => 'Zaalverantwoordelijke',
            'email' => 'zaal@bclandegem.be',
            'password' => Hash::make('geheim-wachtwoord'),
        ]);

        $this->season = Season::create([
            'name' => '2026 - 2027',
            'points_per_set' => $this->format->pointsPerSet,
        ]);
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
                'base_points' => $this->format->startingBasePoints() + $index / 100,
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
        $this->round->update(['date' => today()]);

        $this->getJson('/api/zaal/round')
            ->assertOk()
            ->assertJsonPath('round.id', $this->round->id)
            ->assertJsonPath('round.pointsPerSet', $this->format->value())
            ->assertJsonPath('presentCount', 0)
            ->assertJsonCount(6, 'players')
            ->assertJsonPath('players.0.present', false);
    }

    /**
     * Aanmelden geeft geen speeldag terug, als enige schrijfactie van de zaal-app.
     * Het gebeurt in stoten en de app weet zelf wat een tik verandert; de hele
     * speeldag teruggeven kostte per tik alle leden, alle wedstrijden en de
     * handicap per set, voor een antwoord dat niemand las.
     */
    public function test_aanwezigheid_aanpassen_antwoordt_leeg(): void
    {
        $this->actingAs($this->user);

        $this->postJson("/api/zaal/rounds/{$this->round->id}/attendance", [
            'playerId' => $this->players[1]->id,
            'present' => true,
        ])->assertNoContent();

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
        ])->assertNoContent();

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

        $this->putJson("/api/zaal/games/{$gameId}", $this->format->straightSets())->assertOk()
            ->assertJsonPath('games.0.sets.0.home.score', $this->format->win())
            ->assertJsonPath('games.0.isComplete', true);

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
            $this->format->startingBasePoints(),
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

    public function test_elke_set_toont_welke_duos_tegen_elkaar_speelden(): void
    {
        $this->actingAs($this->user);
        $playerIds = collect(range(1, 4))->map(fn (int $i): int => $this->players[$i]->id)->all();
        $response = $this->postJson("/api/zaal/rounds/{$this->round->id}/games", ['playerIds' => $playerIds])->assertOk();

        $sets = $response->json('games.0.sets');
        $namesOf = fn (array $side): array => array_column($side['players'], 'id');

        // De duo's roteren: 1+2 vs 3+4, dan 1+3 vs 2+4, dan 1+4 vs 2+3.
        $this->assertSame([$playerIds[0], $playerIds[1]], $namesOf($sets[0]['home']));
        $this->assertSame([$playerIds[2], $playerIds[3]], $namesOf($sets[0]['away']));
        $this->assertSame([$playerIds[0], $playerIds[2]], $namesOf($sets[1]['home']));
        $this->assertSame([$playerIds[1], $playerIds[3]], $namesOf($sets[1]['away']));
        $this->assertSame([$playerIds[0], $playerIds[3]], $namesOf($sets[2]['home']));
        $this->assertSame([$playerIds[1], $playerIds[2]], $namesOf($sets[2]['away']));
    }

    public function test_een_match_kan_set_per_set_ingevuld_worden(): void
    {
        $this->actingAs($this->user);
        $playerIds = collect(range(1, 4))->map(fn (int $i): int => $this->players[$i]->id)->all();
        $created = $this->postJson("/api/zaal/rounds/{$this->round->id}/games", ['playerIds' => $playerIds]);
        $gameId = $created->json('games.0.id');

        // Enkel set 1: de match is nog niet volledig, de rest blijft leeg.
        $this->putJson("/api/zaal/games/{$gameId}", $this->format->firstSetOnly())
            ->assertOk()
            ->assertJsonPath('games.0.sets.0.home.score', $this->format->win())
            ->assertJsonPath('games.0.sets.1.home.score', null)
            ->assertJsonPath('games.0.isComplete', false);

        $this->assertFalse($this->round->fresh()->is_calculated, 'Een halve match mag de tussenstand niet berekenen.');

        // Set 2 en 3 erbij: nu pas is de match af.
        $this->putJson("/api/zaal/games/{$gameId}", [
            'set1_home' => $this->format->win(), 'set1_away' => $this->format->lose(),
            'set2_home' => $this->format->win(), 'set2_away' => $this->format->loseClose(),
            'set3_home' => $this->format->loseDeuce(), 'set3_away' => $this->format->win(),
        ])->assertOk()->assertJsonPath('games.0.isComplete', true);

        $this->assertTrue($this->round->fresh()->is_calculated);
    }

    public function test_kandidatenlijst_voor_aanvullen(): void
    {
        $this->actingAs($this->user);
        $this->markPresent(range(1, 6));

        // Speler 5 en 6 zijn uitgeloot, de rest speelt al.
        $this->postJson("/api/zaal/rounds/{$this->round->id}/games", [
            'playerIds' => collect(range(1, 4))->map(fn (int $i): int => $this->players[$i]->id)->all(),
        ]);
        PlayerRoundStatistic::where('round_id', $this->round->id)
            ->whereIn('player_id', [$this->players[5]->id, $this->players[6]->id])
            ->update(['is_drawn_out' => true]);

        $response = $this->getJson("/api/zaal/rounds/{$this->round->id}/fill-candidates")->assertOk();

        $this->assertSame(
            [$this->players[5]->id, $this->players[6]->id],
            array_column($response->json('drawnOut'), 'id'),
        );

        // Aanwezig en niet uitgeloot: de vier die al speelden, met hun aantal matches.
        $present = $response->json('present');
        $this->assertCount(4, $present);
        $this->assertSame(1, $present[0]['gamesPlayed']);

        // Een lid dat nog niet aanwezig staat, hoort bij 'others': dat is de
        // laatkomer die vanuit dit scherm meteen ingeschreven kan worden.
        $laatkomer = Player::create([
            'first_name' => 'Laat', 'last_name' => 'Komer', 'gender' => 'male',
            'birth_date' => '1990-01-01', 'double_ranking' => 5,
            'plays_competition' => true, 'is_member' => true,
        ]);

        $others = $this->getJson("/api/zaal/rounds/{$this->round->id}/fill-candidates")->json('others');
        $this->assertSame([$laatkomer->id], array_column($others, 'id'));
    }

    public function test_aanvullen_met_een_vrijwilliger_die_al_speelde(): void
    {
        $this->actingAs($this->user);
        $this->markPresent(range(1, 6));
        $this->postJson("/api/zaal/rounds/{$this->round->id}/games", [
            'playerIds' => collect(range(1, 4))->map(fn (int $i): int => $this->players[$i]->id)->all(),
        ]);
        PlayerRoundStatistic::where('round_id', $this->round->id)
            ->whereIn('player_id', [$this->players[5]->id, $this->players[6]->id])
            ->update(['is_drawn_out' => true]);

        // De zaal vult aan: de twee uitgelote spelers plus twee vrijwilligers.
        $this->postJson("/api/zaal/rounds/{$this->round->id}/games", [
            'playerIds' => [
                $this->players[5]->id, $this->players[6]->id,
                $this->players[1]->id, $this->players[2]->id,
            ],
        ])->assertOk()->assertJsonCount(2, 'games');

        // De uitgeloot-vlag is weg: zij spelen nu mee.
        $this->assertSame(0, PlayerRoundStatistic::where('round_id', $this->round->id)
            ->where('is_drawn_out', true)->count());
    }

    public function test_een_laatkomer_wordt_aanwezig_gezet_bij_het_aanvullen(): void
    {
        $this->actingAs($this->user);
        $this->markPresent(range(1, 3));

        // Speler 4 stond nog niet aanwezig en komt net binnen.
        $this->postJson("/api/zaal/rounds/{$this->round->id}/games", [
            'playerIds' => collect(range(1, 4))->map(fn (int $i): int => $this->players[$i]->id)->all(),
        ])->assertOk()->assertJsonPath('presentCount', 4);

        $this->assertTrue(
            PlayerRoundStatistic::where('round_id', $this->round->id)
                ->where('player_id', $this->players[4]->id)
                ->value('is_present')
        );
    }

    public function test_spelers_tonen_hun_bonuspunten(): void
    {
        $this->actingAs($this->user);
        $this->round->update(['date' => today()]);

        $vrouwelijkeRecreant = Player::create([
            'first_name' => 'Rita', 'last_name' => 'Recreant', 'gender' => 'female',
            'birth_date' => '1980-01-01', 'double_ranking' => 0,
            'plays_competition' => false, 'is_member' => true,
        ]);

        $response = $this->getJson('/api/zaal/round')->assertOk();
        $spelers = collect($response->json('players'))->keyBy('id');

        // Vrouw (+2) én recreant (+5).
        $this->assertSame(7, $spelers[$vrouwelijkeRecreant->id]['bonusPoints']);

        // Man met dubbelklassement 100 in de fixture: competitie, > 10 → +4.
        $this->assertSame(4, $spelers[$this->players[1]->id]['bonusPoints']);
    }

    public function test_elke_set_toont_de_stand_waarop_de_duos_beginnen(): void
    {
        $this->actingAs($this->user);

        // Bonussommen die per set een ander verschil geven: 4, 5, 6 en 7.
        $this->players[2]->update(['plays_competition' => false]);
        $this->players[3]->update(['gender' => 'female']);
        $this->players[4]->update(['gender' => 'female', 'plays_competition' => false]);

        $playerIds = collect(range(1, 4))->map(fn (int $i): int => $this->players[$i]->id)->all();
        $created = $this->postJson("/api/zaal/rounds/{$this->round->id}/games", ['playerIds' => $playerIds])->assertOk();
        $gameId = $created->json('games.0.id');

        // Set 1: 4+5 tegen 6+7 → verschil 4, het uitduo is zwakker.
        $created->assertJsonPath('games.0.sets.0.home.start', -2);
        $created->assertJsonPath('games.0.sets.0.away.start', 2);

        // Set 2: 4+6 tegen 5+7 → verschil 2.
        $created->assertJsonPath('games.0.sets.1.home.start', -1);
        $created->assertJsonPath('games.0.sets.1.away.start', 1);

        // Set 3: 4+7 tegen 5+6 → even sterk, dus beide op nul en geen uitzondering.
        $created->assertJsonPath('games.0.sets.2.home.start', 0);
        $created->assertJsonPath('games.0.sets.2.away.start', 0);

        // Zodra een set een stand heeft, is dát de waarheid over die set: de
        // startstand verdwijnt en de andere twee houden de hunne.
        $this->putJson("/api/zaal/games/{$gameId}", $this->format->firstSetOnly())
            ->assertOk()
            ->assertJsonPath('games.0.sets.0.home.start', null)
            ->assertJsonPath('games.0.sets.0.away.start', null)
            ->assertJsonPath('games.0.sets.1.away.start', 1);
    }

    public function test_zonder_speeldag_van_vandaag_wordt_er_geen_oude_speeldag_geopend(): void
    {
        $this->actingAs($this->user);
        $this->round->update(['date' => today()->subWeeks(2)]);

        $response = $this->getJson('/api/zaal/round')->assertOk();

        // Cruciaal: geen speeldag, anders belanden aanwezigheden op een afgesloten dag.
        $this->assertNull($response->json('round'));
        $this->assertSame($this->round->id, $response->json('latestRound.id'));
        $this->assertSame($this->season->name, $response->json('seasonName'));
    }

    public function test_speeldag_van_vandaag_starten(): void
    {
        $this->actingAs($this->user);
        $this->round->update(['date' => today()->subWeeks(2), 'number' => 7]);

        $response = $this->postJson('/api/zaal/rounds')->assertCreated();

        $this->assertSame(8, $response->json('round.number'), 'Het nummer volgt op de vorige speeldag.');
        $this->assertSame(today()->toDateString(), $response->json('round.date'));
        $this->assertTrue($response->json('round.isToday'));
    }

    public function test_speeldag_starten_opent_de_bestaande_speeldag_van_vandaag(): void
    {
        $this->actingAs($this->user);
        $this->round->update(['date' => today()]);

        $this->postJson('/api/zaal/rounds')
            ->assertCreated()
            ->assertJsonPath('round.id', $this->round->id);

        $this->assertSame(1, $this->season->rounds()->count(), 'Er mag geen tweede speeldag voor vandaag ontstaan.');
    }

    public function test_een_oudere_speeldag_wordt_als_niet_vandaag_gemarkeerd(): void
    {
        $this->actingAs($this->user);
        $this->round->update(['date' => today()->subWeeks(2)]);

        $this->getJson("/api/zaal/rounds/{$this->round->id}")
            ->assertOk()
            ->assertJsonPath('round.isToday', false);
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
