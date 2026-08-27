<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetPasswordTest extends TestCase
{
    use RefreshDatabase;

    public function test_het_zet_het_wachtwoord(): void
    {
        $user = User::factory()->create(['email' => 'beheer@bclandegem.be']);

        $this->artisan('intraclub:set-password', ['email' => 'beheer@bclandegem.be'])
            ->expectsQuestion('Nieuw wachtwoord', 'een-lang-wachtwoord')
            ->expectsQuestion('Nogmaals ter bevestiging', 'een-lang-wachtwoord')
            ->assertSuccessful();

        $this->assertTrue(Hash::check('een-lang-wachtwoord', $user->fresh()->password));
    }

    public function test_het_weigert_bij_twee_verschillende_wachtwoorden(): void
    {
        $user = User::factory()->create(['email' => 'beheer@bclandegem.be']);
        $origineel = $user->password;

        $this->artisan('intraclub:set-password', ['email' => 'beheer@bclandegem.be'])
            ->expectsQuestion('Nieuw wachtwoord', 'wachtwoord-een')
            ->expectsQuestion('Nogmaals ter bevestiging', 'wachtwoord-twee')
            ->assertFailed();

        $this->assertSame($origineel, $user->fresh()->password);
    }

    public function test_het_meldt_een_onbekend_adres(): void
    {
        $this->artisan('intraclub:set-password', ['email' => 'bestaatniet@bclandegem.be'])
            ->expectsOutputToContain('Geen gebruiker met e-mailadres')
            ->assertFailed();
    }
}
