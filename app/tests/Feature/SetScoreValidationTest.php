<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;
use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PlaysToPoints;
use Tests\TestCase;

/**
 * Wat de zaal-API als setstand aanvaardt.
 *
 * De regel zelf staat in {@see PointsPerSet::allowsSet()} en is daar
 * uitputtend getest; hier gaat het erom dat de API hem toepast, dat hij de schaal
 * van het juiste seizoen gebruikt, en dat een geweigerde invoer niets bewaart.
 *
 * Draait voor sets tot 15 en tot 21: zie {@see SetScoreValidationPlayedTo21Test}.
 */
class SetScoreValidationTest extends TestCase
{
    use PlaysToPoints;
    use RefreshDatabase;

    private Round $round;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootFormat();

        $this->actingAs(User::create([
            'name' => 'Zaalverantwoordelijke',
            'email' => 'zaal@bclandegem.be',
            'password' => 'geheim-wachtwoord',
        ]));

        $season = Season::create([
            'name' => '2026 - 2027',
            'points_per_set' => $this->format->pointsPerSet,
        ]);
        $this->round = $season->rounds()->create(['number' => 1, 'date' => '2026-09-01']);

        $players = [];
        foreach (range(1, 4) as $index) {
            $players[$index] = Player::create([
                'first_name' => sprintf('Speler%02d', $index),
                'last_name' => 'Test',
                'gender' => 'male',
                'birth_date' => '1990-01-01',
                'double_ranking' => 100,
                'plays_competition' => true,
                'is_member' => true,
            ]);

            PlayerSeasonStatistic::create([
                'season_id' => $season->id,
                'player_id' => $players[$index]->id,
                'base_points' => $this->format->startingBasePoints(),
            ]);
        }

        $this->game = $this->round->games()->create([
            'player1_id' => $players[1]->id,
            'player2_id' => $players[2]->id,
            'player3_id' => $players[3]->id,
            'player4_id' => $players[4]->id,
        ]);
    }

    /** @param array<string, int|null> $scores */
    private function save(array $scores): TestResponse
    {
        return $this->putJson("/api/zaal/games/{$this->game->id}", $scores + [
            'set1_home' => null, 'set1_away' => null,
            'set2_home' => null, 'set2_away' => null,
            'set3_home' => null, 'set3_away' => null,
        ]);
    }

    public function test_een_winnaar_die_te_ver_voorstaat_wordt_geweigerd(): void
    {
        // Tot 15 is dit 13-16: voorbij het setmaximum kan alleen met twee verschil.
        $home = $this->format->value() - 2;
        $away = $this->format->value() + 1;
        $rule = $this->format->pointsPerSet->setRule();

        $this->save(['set1_home' => $home, 'set1_away' => $away])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['set1_home'])
            ->assertJsonFragment(['set1_home' => ["Set 1: {$home}-{$away} kan niet. {$rule}"]]);

        $this->assertNull($this->game->fresh()->set1_home, 'Een geweigerde invoer hoort niets te bewaren.');
    }

    public function test_een_punt_verschil_op_het_setmaximum_wordt_geweigerd(): void
    {
        // Tot 15 is dit 15-14: op 14-14 wordt doorgespeeld.
        $this->save([
            'set1_home' => $this->format->win(),
            'set1_away' => $this->format->win() - 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['set1_home']);
    }

    public function test_de_melding_hangt_aan_het_thuisvak_zodat_ze_maar_een_keer_verschijnt(): void
    {
        $response = $this->save([
            'set2_home' => $this->format->value() - 2,
            'set2_away' => $this->format->value() + 1,
        ])->assertStatus(422);

        $this->assertSame(['set2_home'], array_keys($response->json('errors')));
    }

    public function test_een_half_ingevulde_set_wordt_geweigerd(): void
    {
        $this->save(['set3_home' => $this->format->win()])
            ->assertStatus(422)
            ->assertJsonFragment(['set3_home' => ['Set 3: vul beide punten in of laat beide leeg.']]);
    }

    public function test_een_getal_boven_de_cap_krijgt_zijn_eigen_melding(): void
    {
        $cap = $this->format->cap();

        $this->save(['set1_home' => $cap + 1, 'set1_away' => $cap - 1])
            ->assertStatus(422)
            ->assertJsonFragment(['set1_home' => ["Meer dan {$cap} punten kan niet in een set."]]);
    }

    public function test_een_negatieve_stand_wordt_geweigerd(): void
    {
        $this->save(['set1_home' => -1, 'set1_away' => $this->format->win()])
            ->assertStatus(422)
            ->assertJsonFragment(['set1_home' => ['Een setstand kan niet negatief zijn.']]);
    }

    public function test_de_langst_mogelijke_verlenging_wordt_aanvaard(): void
    {
        $cap = $this->format->cap();

        $this->save([
            'set1_home' => $cap, 'set1_away' => $cap - 1,
            'set2_home' => $cap - 2, 'set2_away' => $cap,
            'set3_home' => $this->format->win(), 'set3_away' => $this->format->loseDeuce(),
        ])->assertOk();

        $this->assertSame($cap, $this->game->fresh()->set1_home);
    }

    public function test_lege_sets_blijven_toegestaan(): void
    {
        $this->save([
            'set1_home' => $this->format->win(),
            'set1_away' => $this->format->lose(),
        ])->assertOk()->assertJsonPath('games.0.isComplete', false);
    }

    public function test_de_speeldag_vertelt_de_zaal_app_welke_grenzen_gelden(): void
    {
        // De zaal-app valideert live mee en mag die getallen niet zelf afleiden.
        $this->getJson('/api/zaal/round')->assertOk();

        $this->getJson("/api/zaal/rounds/{$this->round->id}")
            ->assertOk()
            ->assertJsonPath('round.pointsPerSet', $this->format->value())
            ->assertJsonPath('round.maxScore', $this->format->cap());
    }

    public function test_de_schaal_van_het_seizoen_bepaalt_wat_kan(): void
    {
        // 16-14 kan enkel bij sets tot 15; bij sets tot 21 haalt niemand het maximum.
        $response = $this->save(['set1_home' => 16, 'set1_away' => 14]);

        $this->format->value() === 15
            ? $response->assertOk()
            : $response->assertStatus(422);
    }
}
