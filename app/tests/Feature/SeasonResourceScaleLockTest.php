<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;
use App\Filament\Resources\Seasons\Pages\ManageSeasons;
use App\Models\Season;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SeasonObserver houdt een schaalwijziging tegen met een exception. Het beheerspaneel
 * hoort de beheerder daar niet tegenaan te laten lopen: het veld gaat op slot zodra
 * het seizoen een speeldag heeft, met een reden erbij.
 */
class SeasonResourceScaleLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_de_schaal_blijft_aanpasbaar_zolang_het_seizoen_leeg_is(): void
    {
        $season = Season::create([
            'name' => '2026 - 2027',
            'points_per_set' => PointsPerSet::Fifteen,
        ]);

        Livewire::test(ManageSeasons::class)
            ->mountAction(TestAction::make('edit')->table($season))
            ->assertFormFieldEnabled('points_per_set');
    }

    public function test_de_schaal_gaat_op_slot_zodra_er_een_speeldag_staat(): void
    {
        $season = Season::create([
            'name' => '2026 - 2027',
            'points_per_set' => PointsPerSet::Fifteen,
        ]);
        $season->rounds()->create(['number' => 1, 'date' => '2026-09-01']);

        Livewire::test(ManageSeasons::class)
            ->mountAction(TestAction::make('edit')->table($season))
            ->assertFormFieldDisabled('points_per_set')
            // De naam blijft wél te corrigeren; dat is precies de wijziging die geen
            // gevolgen mag hebben voor de berekende stand.
            ->assertFormFieldEnabled('name');
    }
}
