<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;
use App\Filament\Resources\Rounds\Pages\EditRound;
use App\Filament\Resources\Rounds\RelationManagers\GamesRelationManager;
use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Het beheerspaneel is de plek waar gecorrigeerd wordt, en dus precies de plek
 * waar een onmogelijke stand niet alsnog binnen mag glippen. Zelfde regel als de
 * zaal-API, zelfde meldingen — er is geen ontsnapping voor de beheerder.
 */
class GameScoreResourceValidationTest extends TestCase
{
    use RefreshDatabase;

    private Round $round;

    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $season = Season::create([
            'name' => '2026 - 2027',
            'points_per_set' => PointsPerSet::Fifteen,
        ]);
        $this->round = $season->rounds()->create(['number' => 1, 'date' => '2026-09-01']);

        $players = collect(range(1, 4))->map(fn (int $index): Player => Player::create([
            'first_name' => sprintf('Speler%02d', $index),
            'last_name' => 'Test',
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'double_ranking' => 100,
            'plays_competition' => true,
            'is_member' => true,
        ]));

        $this->game = $this->round->games()->create([
            'player1_id' => $players[0]->id,
            'player2_id' => $players[1]->id,
            'player3_id' => $players[2]->id,
            'player4_id' => $players[3]->id,
        ]);
    }

    /** @param array<string, int|null> $scores */
    private function edit(array $scores): Testable
    {
        return Livewire::test(GamesRelationManager::class, [
            'ownerRecord' => $this->round,
            'pageClass' => EditRound::class,
        ])
            ->callAction(TestAction::make('edit')->table($this->game), $scores + [
                'player1_id' => $this->game->player1_id,
                'player2_id' => $this->game->player2_id,
                'player3_id' => $this->game->player3_id,
                'player4_id' => $this->game->player4_id,
            ]);
    }

    public function test_een_onmogelijke_stand_wordt_geweigerd(): void
    {
        $this->edit(['set1_home' => 13, 'set1_away' => 16])
            ->assertHasActionErrors(['set1_home']);

        $this->assertNull($this->game->fresh()->set1_home);
    }

    public function test_een_getal_boven_de_cap_wordt_geweigerd(): void
    {
        $this->edit(['set1_home' => 25, 'set1_away' => 23])
            ->assertHasActionErrors(['set1_home']);
    }

    public function test_een_half_ingevulde_set_wordt_geweigerd(): void
    {
        $this->edit(['set2_home' => 15])
            ->assertHasActionErrors(['set2_home']);
    }

    public function test_een_geldige_stand_wordt_bewaard(): void
    {
        $this->edit([
            'set1_home' => 15, 'set1_away' => 13,
            'set2_home' => 21, 'set2_away' => 20,
            'set3_home' => 16, 'set3_away' => 14,
        ])->assertHasNoActionErrors();

        $this->assertSame([15, 13, 21, 20, 16, 14], [
            $this->game->fresh()->set1_home,
            $this->game->fresh()->set1_away,
            $this->game->fresh()->set2_home,
            $this->game->fresh()->set2_away,
            $this->game->fresh()->set3_home,
            $this->game->fresh()->set3_away,
        ]);
    }
}
