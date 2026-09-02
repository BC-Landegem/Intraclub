<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;
use App\Models\Player;
use App\Models\PlayerSeasonStatistic;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * De klassementpagina leest de array die RankingService teruggeeft rechtstreeks
 * in Blade uit. Toen die sleutels van camelCase naar snake_case gingen bleef de
 * view achter en gaf /admin/klassement een 500 (undefined array key). Deze suite
 * rendert de pagina echt, zodat een volgende omdoping hier struikelt.
 */
class KlassementPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $season = Season::create([
            'name' => '2026 - 2027',
            'points_per_set' => PointsPerSet::Fifteen,
        ]);

        // Eén speler per categorie, zodat elke sectie van de pagina rijen heeft.
        $players = [
            ['first_name' => 'Anna', 'gender' => 'female', 'birth_date' => '1995-01-01', 'plays_competition' => true],
            ['first_name' => 'Bert', 'gender' => 'male', 'birth_date' => '1960-01-01', 'plays_competition' => true],
            ['first_name' => 'Carl', 'gender' => 'male', 'birth_date' => '1995-01-01', 'plays_competition' => false],
        ];

        foreach ($players as $index => $attributes) {
            $player = Player::create($attributes + [
                'last_name' => 'Testspeler',
                'double_ranking' => 100,
                'is_member' => true,
            ]);

            PlayerSeasonStatistic::create([
                'season_id' => $season->id,
                'player_id' => $player->id,
                'base_points' => 20 - $index,
            ]);
        }
    }

    public function test_de_klassementpagina_rendert_met_spelers_in_elke_categorie(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/klassement')
            ->assertSuccessful()
            ->assertSee('Anna Testspeler')
            ->assertSee('Bert Testspeler')
            ->assertSee('Carl Testspeler')
            ->assertDontSee('Geen spelers in deze categorie.');
    }
}
