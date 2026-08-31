<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Filament weigert het paneel met een 403 zodra APP_ENV niet 'local' is en het
 * User-model geen FilamentUser implementeert. Deze suite draait op 'testing',
 * dus dekt hetzelfde pad als productie.
 */
class AdminPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_een_aangemelde_gebruiker_raakt_aan_het_beheerspaneel(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertSuccessful();
    }

    public function test_wie_niet_aangemeld_is_gaat_naar_de_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }
}
