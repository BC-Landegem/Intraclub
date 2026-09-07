<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;
use App\Models\Player;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use App\Models\User;
use App\Services\SeasonCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wie een tijd niet meespeelde zakt in de lopende stand naar onderaan en toont
 * geen gemiddelde meer.
 *
 * Waar het op aankomt, en wat deze suite vastlegt: `rank` blijft de plaats
 * volgens de gemiddelden. Enkel de leesorde verandert. Zou de plaats meeschuiven,
 * dan zou ze niet langer kloppen met de bevroren rank in de historiek, met de
 * eindstand op de spelersfiche, of met de basispunten van het volgende seizoen.
 */
class RankingActivityTest extends TestCase
{
    use RefreshDatabase;

    private Season $season;

    /** @var array<string, Player> */
    private array $players = [];

    /** @var array<int, Round> */
    private array $rounds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->season = Season::create([
            'name' => '2026 - 2027',
            'points_per_set' => PointsPerSet::Fifteen,
        ]);

        foreach (['Ann', 'Bob', 'Cas', 'Dirk', 'Els', 'Finn'] as $index => $firstName) {
            $player = Player::create([
                'first_name' => $firstName,
                'last_name' => 'Test',
                'gender' => 'male',
                'birth_date' => '1990-01-01',
                'double_ranking' => 100,
                'plays_competition' => true,
                'is_member' => true,
            ]);
            $this->players[$firstName] = $player;

            PlayerSeasonStatistic::create([
                'season_id' => $this->season->id,
                'player_id' => $player->id,
                'base_points' => 14.0 + $index / 10,
            ]);
        }

        // Vier speeldagen. Ann en Bob spelen enkel op de eerste mee, dus met een
        // venster van drie speeldagen staan zij op speeldag 4 buiten.
        foreach (range(1, 4) as $number) {
            $this->rounds[$number] = $this->season->rounds()->create([
                'number' => $number,
                'date' => "2026-09-0{$number}",
            ]);

            $lineUp = $number === 1
                ? ['Ann', 'Bob', 'Cas', 'Dirk']
                : ['Cas', 'Dirk', 'Els', 'Finn'];

            // Een volledige game laat de GameObserver het seizoen herberekenen, dus
            // hierna is de speeldag berekend en heeft elke speler een gemiddelde.
            $this->rounds[$number]->games()->create([
                'player1_id' => $this->players[$lineUp[0]]->id,
                'player2_id' => $this->players[$lineUp[1]]->id,
                'player3_id' => $this->players[$lineUp[2]]->id,
                'player4_id' => $this->players[$lineUp[3]]->id,
                'set1_home' => 15, 'set1_away' => 11,
                'set2_home' => 15, 'set2_away' => 11,
                'set3_home' => 15, 'set3_away' => 11,
            ]);
        }

        // De gemiddelden zelf zijn hier niet het onderwerp; deze waarden maken de
        // verwachte orde eenduidig. Ann staat eerste op gemiddelde, Bob laatste.
        $this->setAverages(['Ann' => 15.0, 'Cas' => 14.0, 'Dirk' => 13.0, 'Els' => 12.0, 'Finn' => 11.0, 'Bob' => 10.0]);
    }

    public function test_inactieve_spelers_staan_achteraan_maar_houden_hun_plaats(): void
    {
        $data = $this->getJson('/api/rankings/general')->assertOk()->json('data');

        // Ann (plaats 1) en Bob (plaats 6) speelden de laatste drie speeldagen niet.
        $this->assertSame(
            ['Cas', 'Dirk', 'Els', 'Finn', 'Ann', 'Bob'],
            array_column($data, 'first_name'),
        );
        $this->assertSame([2, 3, 4, 5, 1, 6], array_column($data, 'rank'));
        // Loos vergelijken: een rond gemiddelde komt als 14 uit de JSON, niet 14.0.
        $this->assertEquals([14.0, 13.0, 12.0, 11.0, null, null], array_column($data, 'average'));
    }

    public function test_een_top_drie_geeft_de_drie_beste_actieve_spelers(): void
    {
        $data = $this->getJson('/api/rankings/general?limit=3')->assertOk()->json('data');

        $this->assertSame(['Cas', 'Dirk', 'Els'], array_column($data, 'first_name'));
    }

    public function test_de_plaats_in_het_klassement_blijft_die_van_de_historiek(): void
    {
        $klassement = collect($this->getJson('/api/rankings/general')->json('data'))->keyBy('id');

        $bevroren = DB::table('player_round_statistics')
            ->where('round_id', $this->rounds[4]->id)
            ->pluck('rank', 'player_id');

        foreach ($klassement as $playerId => $entry) {
            $this->assertSame((int) $bevroren[$playerId], $entry['rank']);
        }
    }

    public function test_de_eindstand_van_een_afgesloten_seizoen_verbergt_niets(): void
    {
        $afgesloten = $this->season;

        // Zodra er een volgend seizoen is, is dit een eindstand: die verandert nooit
        // meer en staat al op de erelijst, dus Ann blijft er de winnaar van.
        Season::create(['name' => '2027 - 2028', 'points_per_set' => PointsPerSet::Fifteen]);

        $data = $this->getJson("/api/rankings/general?season={$afgesloten->id}&members=0")
            ->assertOk()
            ->json('data');

        $this->assertSame(['Ann', 'Cas', 'Dirk', 'Els', 'Finn', 'Bob'], array_column($data, 'first_name'));
        $this->assertEquals([15.0, 14.0, 13.0, 12.0, 11.0, 10.0], array_column($data, 'average'));
    }

    public function test_basispunten_van_het_nieuwe_seizoen_volgen_het_gemiddelde(): void
    {
        $nieuw = app(SeasonCreator::class)->create('2027 - 2028');

        $basePoints = $nieuw->playerStatistics()
            ->pluck('base_points', 'player_id')
            ->map(fn ($points): float => (float) $points);

        // Ann heeft het hoogste gemiddelde, dus de hoogste basispunten — dat ze de
        // laatste speeldagen niet meespeelde mag daar niets aan veranderen.
        $this->assertGreaterThan(
            $basePoints[$this->players['Cas']->id],
            $basePoints[$this->players['Ann']->id],
        );
        $this->assertSame(
            14.0,
            $basePoints[$this->players['Bob']->id],
            'De laatste in de eindstand start op de laagste basispunten.',
        );
    }

    public function test_zonder_berekende_speeldag_staat_de_stand_op_de_basispunten(): void
    {
        $nieuw = app(SeasonCreator::class)->create('2027 - 2028');
        $nieuw->rounds()->create(['number' => 1, 'date' => '2027-09-01']);

        $data = $this->getJson('/api/rankings/general')->assertOk()->json('data');

        // Niemand speelde al, dus valt er niets te beoordelen: iedereen zichtbaar.
        $this->assertCount(6, $data);
        $this->assertNotContains(null, array_column($data, 'average'));
    }

    public function test_de_regel_is_uit_te_zetten_zonder_deploy(): void
    {
        config(['ranking.active_rounds' => 0]);

        $data = $this->getJson('/api/rankings/general')->assertOk()->json('data');

        $this->assertSame(['Ann', 'Cas', 'Dirk', 'Els', 'Finn', 'Bob'], array_column($data, 'first_name'));
        $this->assertNotContains(null, array_column($data, 'average'));
    }

    public function test_een_speeldag_uit_een_ander_seizoen_geeft_404(): void
    {
        $ander = Season::create(['name' => '2027 - 2028', 'points_per_set' => PointsPerSet::Fifteen]);
        $anderRound = $ander->rounds()->create(['number' => 1, 'date' => '2027-09-01']);

        $this->getJson("/api/rankings/general?season={$this->season->id}&round={$anderRound->id}")
            ->assertNotFound();
    }

    public function test_de_klassementpagina_toont_niet_actief_in_plaats_van_een_cijfer(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/klassement')
            ->assertSuccessful()
            ->assertSee('Niet actief')
            ->assertSee('14,00');
    }

    /** @param  array<string, float>  $averages */
    private function setAverages(array $averages): void
    {
        foreach ($averages as $firstName => $average) {
            DB::table('player_round_statistics')
                ->where('round_id', $this->rounds[4]->id)
                ->where('player_id', $this->players[$firstName]->id)
                ->update(['average' => $average]);
        }

        // De bevroren rank hoort bij die gemiddelden, want de historiek en het
        // klassement moeten dezelfde plaats geven.
        $ids = DB::table('player_round_statistics')
            ->where('round_id', $this->rounds[4]->id)
            ->orderByDesc('average')
            ->pluck('id');

        foreach ($ids as $position => $id) {
            DB::table('player_round_statistics')->where('id', $id)->update(['rank' => $position + 1]);
        }
    }
}
