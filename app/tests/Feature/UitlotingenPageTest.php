<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;
use App\Filament\Pages\Uitlotingen;
use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerRoundStatistic;
use App\Models\Round;
use App\Models\Season;
use App\Models\User;
use App\Services\DrawService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/*
 * Alles op dit scherm is afgeleid: een fout uit zich als een verkeerd getal, niet
 * als een crash. Vastgelegd zijn daarom de gevallen waarin een verkeerd getal er
 * geloofwaardig uitziet — de laatkomer die de vlag wist, het ex-lid dat zijn
 * geschiedenis houdt, en de schildgrens die precies op DrawService::PROTECTED_ROUNDS
 * ligt, gerekend vanaf de volgende speeldag.
 */
class UitlotingenPageTest extends TestCase
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

        foreach (range(1, 11) as $number) {
            $this->rounds[$number] = Round::create([
                'season_id' => $this->season->id,
                'number' => $number,
                'date' => now()->subWeeks(11 - $number)->toDateString(),
            ]);
        }

        // Lid, drie keer aan de kant, laatst op speeldag 11: beschermd bij 12.
        $this->player('jan', drawnOut: [2, 7, 11], present: range(1, 11));

        // Lid, gat van 4 tussen zijn laatste uitloting en de volgende speeldag: nog
        // net beschermd.
        $this->player('els', drawnOut: [8], present: range(1, 11));

        // Lid, gat van 5: niet meer beschermd. Samen met Els de grens.
        $this->player('bart', drawnOut: [7], present: range(1, 11));

        // Lid, twee uitlotingen lang geleden.
        $this->player('rik', drawnOut: [2, 3], present: [1, 2, 3, 4]);

        // Lid, altijd aanwezig, nooit uitgeloot: hoort er met 0 bij te staan.
        $this->player('marie', drawnOut: [], present: range(1, 11));

        // Geen lid meer, maar wel uitgeloot dit seizoen: houdt zijn rij.
        $this->player('geert', drawnOut: [1, 5], present: [1, 2, 3, 4, 5, 6], isMember: false);

        // Geen lid en nooit uitgeloot: loot niet mee, dus geen rij.
        $this->player('gast', drawnOut: [], present: [1, 2], isMember: false);

        // Lid dat dit seizoen nooit kwam: geen rij.
        $this->player('piet', drawnOut: [], present: []);
    }

    public function test_de_pagina_rendert_voor_een_admin(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin/uitlotingen')
            ->assertSuccessful()
            ->assertSee('Jan Testspeler')
            ->assertSee('11 speeldagen');
    }

    public function test_een_zaalaccount_raakt_niet_aan_de_pagina(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get('/admin/uitlotingen')->assertForbidden();
    }

    public function test_telt_de_uitlotingen_en_de_aanwezigheden_van_het_seizoen(): void
    {
        $this->page()
            ->assertTableColumnStateSet('drawn_out_count', 3, $this->players['jan'])
            ->assertTableColumnStateSet('present_count', 11, $this->players['jan'])
            ->assertTableColumnStateSet('drawn_out_rounds', '2, 7, 11', $this->players['jan'])
            ->assertTableColumnStateSet('drawn_out_count', 2, $this->players['rik'])
            ->assertTableColumnStateSet('present_count', 4, $this->players['rik'])
            ->assertTableColumnStateSet('drawn_out_rounds', '2, 3', $this->players['rik'])
            ->assertTableColumnStateSet('drawn_out_count', 0, $this->players['marie'])
            ->assertTableColumnStateSet('drawn_out_rounds', '—', $this->players['marie'])
            ->assertTableColumnStateSet('rounds_since', '—', $this->players['marie']);
    }

    /*
     * De vlag is niet blijvend: wie na de loting toch in een match komt, speelde die
     * avond mee. Het scherm hoort hem dan niet als aan-de-kant te tellen.
     */
    public function test_wie_door_een_laatkomer_toch_speelde_telt_niet_mee(): void
    {
        $kris = $this->player('kris', drawnOut: [4], present: range(1, 11));

        $this->page()->assertTableColumnStateSet('drawn_out_count', 1, $kris);

        Game::create([
            'round_id' => $this->rounds[4]->id,
            'player1_id' => $kris->id,
            'player2_id' => $this->players['marie']->id,
            'player3_id' => $this->players['bart']->id,
            'player4_id' => $this->players['els']->id,
        ]);

        $this->page()
            ->assertTableColumnStateSet('drawn_out_count', 0, $kris)
            ->assertTableColumnStateSet('drawn_out_rounds', '—', $kris);
    }

    public function test_een_ex_lid_met_uitlotingen_houdt_zijn_rij_en_een_niet_lid_zonder_niet(): void
    {
        $this->page()
            ->assertCanSeeTableRecords([$this->players['geert']])
            ->assertTableColumnStateSet('drawn_out_count', 2, $this->players['geert'])
            ->assertCanNotSeeTableRecords([$this->players['gast'], $this->players['piet']]);
    }

    /*
     * Sinds rekent vanaf de volgende speeldag (12), niet vanaf de laatste (11):
     * anders krijgt wie gisteren uitviel een schild terwijl gisteren in zijn eigen
     * lijst staat.
     */
    public function test_sinds_rekent_vanaf_de_volgende_speeldag(): void
    {
        $this->page()
            ->assertTableColumnStateSet('rounds_since', '1', $this->players['jan'])
            ->assertTableColumnStateSet('rounds_since', '9', $this->players['rik']);
    }

    public function test_het_schild_ligt_op_de_grens_van_de_bescherming(): void
    {
        $this->assertSame(4, DrawService::PROTECTED_ROUNDS);

        $page = $this->page();

        // Els viel uit op speeldag 8, dus 4 speeldagen vóór de volgende: beschermd.
        $page->assertTableColumnStateSet('rounds_since', '4', $this->players['els']);
        $this->assertTrue($this->hasShield($page, $this->players['els']));

        // Bart viel uit op 7, dus 5 speeldagen: niet meer beschermd.
        $page->assertTableColumnStateSet('rounds_since', '5', $this->players['bart']);
        $this->assertFalse($this->hasShield($page, $this->players['bart']));

        $this->assertFalse($this->hasShield($page, $this->players['marie']));
    }

    public function test_een_ander_seizoen_toont_zijn_eigen_uitlotingen(): void
    {
        $other = Season::create([
            'name' => '2027 - 2028',
            'points_per_set' => PointsPerSet::Fifteen,
        ]);

        $round = Round::create([
            'season_id' => $other->id,
            'number' => 1,
            'date' => now()->addWeek()->toDateString(),
        ]);

        PlayerRoundStatistic::create([
            'round_id' => $round->id,
            'player_id' => $this->players['marie']->id,
            'is_present' => true,
            'is_drawn_out' => true,
        ]);

        Livewire::test(Uitlotingen::class)
            ->set('seasonId', $other->id)
            ->assertTableColumnStateSet('drawn_out_count', 1, $this->players['marie'])
            ->assertTableColumnStateSet('rounds_since', '1', $this->players['marie'])
            ->assertCanNotSeeTableRecords([$this->players['jan']]);
    }

    private function page(): Testable
    {
        return Livewire::test(Uitlotingen::class);
    }

    private function hasShield(mixed $page, Player $player): bool
    {
        $column = $page->instance()->getTable()->getColumn('rounds_since')
            ->record($page->instance()->getTableRecord((string) $player->getKey()));

        $column->clearCachedState();

        return $column->getIcon($column->getState()) !== null;
    }

    /**
     * @param  list<int>  $drawnOut  speeldagnummers waarop de speler aan de kant bleef
     * @param  list<int>  $present  speeldagnummers waarop hij aanwezig was
     */
    private function player(string $key, array $drawnOut, array $present, bool $isMember = true): Player
    {
        $player = Player::create([
            'first_name' => ucfirst($key),
            'last_name' => 'Testspeler',
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'double_ranking' => 100,
            'plays_competition' => true,
            'is_member' => $isMember,
        ]);

        foreach ($present as $number) {
            PlayerRoundStatistic::create([
                'round_id' => $this->rounds[$number]->id,
                'player_id' => $player->id,
                'is_present' => true,
                'is_drawn_out' => in_array($number, $drawnOut, true),
            ]);
        }

        return $this->players[$key] = $player;
    }
}
