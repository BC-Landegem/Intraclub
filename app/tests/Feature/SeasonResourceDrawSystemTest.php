<?php

namespace Tests\Feature;

use App\Enums\DrawSystem;
use App\Enums\PointsPerSet;
use App\Filament\Resources\Seasons\Pages\ManageSeasons;
use App\Models\Game;
use App\Models\Player;
use App\Models\Season;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Het lotingsysteem in het beheerspaneel: het keuzeveld en de twee meetcijfers.
 *
 * De schaal hierboven gaat op slot zodra er een speeldag staat (zie
 * {@see SeasonResourceScaleLockTest}); de loting juist niet. Dat verschil is de kern
 * van de keuze en hoort dus vast te liggen: een wissel maakt geen enkel bewaard getal
 * ongeldig, dus een paar avonden proberen moet kunnen.
 */
class SeasonResourceDrawSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    private function makeSeason(): Season
    {
        return Season::create([
            'name' => '2026 - 2027',
            'points_per_set' => PointsPerSet::Fifteen,
        ]);
    }

    public function test_de_loting_blijft_te_wisselen_ook_met_speeldagen_erin(): void
    {
        $season = $this->makeSeason();
        $season->rounds()->create(['number' => 1, 'date' => '2026-09-01']);

        Livewire::test(ManageSeasons::class)
            ->mountAction(TestAction::make('edit')->table($season))
            ->assertFormFieldDisabled('points_per_set')
            ->assertFormFieldEnabled('draw_system')
            ->fillForm(['draw_system' => DrawSystem::VaryingOpponents->value])
            ->callMountedAction();

        $this->assertSame(DrawSystem::VaryingOpponents, $season->fresh()->draw_system);
    }

    public function test_een_nieuw_seizoen_staat_standaard_op_sterktegroepen(): void
    {
        $season = $this->makeSeason();

        $this->assertSame(DrawSystem::StrengthGroups, $season->draw_system);
    }

    public function test_de_seizoenentabel_toont_de_spreidingscijfers(): void
    {
        $season = $this->makeSeason();
        $round = $season->rounds()->create(['number' => 1, 'date' => '2026-09-01']);

        $players = collect(range(1, 4))->map(fn (int $index): Player => Player::create([
            'first_name' => sprintf('Speler%02d', $index),
            'last_name' => 'Test',
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'double_ranking' => 0,
            'plays_competition' => true,
            'is_member' => true,
        ]));

        $round->games()->create([
            'player1_id' => $players[0]->id,
            'player2_id' => $players[1]->id,
            'player3_id' => $players[2]->id,
            'player4_id' => $players[3]->id,
        ]);

        // Vier spelers op één baan: elk ziet drie verschillende tegenstanders, en
        // geen enkel koppel komt elkaar twee keer tegen.
        Livewire::test(ManageSeasons::class)
            ->assertCanSeeTableRecords([$season])
            ->assertTableColumnStateSet('spread', '3,0 · max 1×', $season);
    }

    public function test_een_seizoen_zonder_wedstrijden_toont_een_streepje(): void
    {
        $season = $this->makeSeason();

        $this->assertSame(0, Game::count());

        Livewire::test(ManageSeasons::class)
            ->assertTableColumnStateSet('spread', '—', $season);
    }
}
