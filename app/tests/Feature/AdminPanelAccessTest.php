<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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

    public function test_een_zaalaccount_raakt_niet_aan_het_beheerspaneel(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get('/admin')->assertForbidden();
    }

    /*
     * De grens ligt op het paneel en niet op de login: het zaaltoestel moet met
     * dit account wel de speeldag kunnen invullen.
     */
    public function test_een_zaalaccount_kan_wel_inloggen_en_aan_de_zaal_api(): void
    {
        User::factory()->create([
            'email' => 'zaal@bclandegem.be',
            'password' => 'geheim-wachtwoord',
            'is_admin' => false,
        ]);

        $this->postJson('/api/login', [
            'email' => 'zaal@bclandegem.be',
            'password' => 'geheim-wachtwoord',
        ])->assertSuccessful();

        $this->getJson('/api/me')
            ->assertSuccessful()
            ->assertJsonPath('email', 'zaal@bclandegem.be');
    }

    /*
     * Bestaande accounts en `make:filament-user` kennen de kolom niet, dus de
     * kolomdefault moet toegang geven en niet weigeren.
     */
    public function test_een_nieuwe_gebruiker_is_standaard_beheerder(): void
    {
        $user = User::create([
            'name' => 'Nieuwe beheerder',
            'email' => 'nieuw@bclandegem.be',
            'password' => 'geheim-wachtwoord',
        ]);

        $this->assertTrue($user->refresh()->is_admin);
    }

    public function test_de_schakelaar_uit_maakt_een_zaalaccount(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Zaaltoestel',
                'email' => 'zaal@bclandegem.be',
                'password' => 'geheim-wachtwoord',
                'is_admin' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse(User::query()->where('email', 'zaal@bclandegem.be')->firstOrFail()->is_admin);
    }

    /*
     * Op je eigen rij staat de schakelaar niet enkel grijs: hij wordt ook niet
     * opgeslagen. Wie zichzelf buitensluit, kan het nergens meer terugzetten en
     * heeft een shell op de server nodig — en die is er op de hosting niet.
     */
    public function test_je_kan_je_eigen_toegang_niet_afnemen(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['is_admin' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($admin->refresh()->is_admin);
    }
}
