<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * De endpoints zelf draaien enkel op productie (MySQL). Wat hier getest wordt is
 * de poortwachter: wie er niet in mag, mag er niet in — en de reset weigert
 * zolang niet álle remmen los staan.
 */
class DeployRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_zonder_token_in_de_configuratie_bestaan_de_routes_niet(): void
    {
        config(['deploy.token' => null]);

        $this->postJson('/api/deploy/migrate')->assertNotFound();
        $this->postJson('/api/deploy/reset')->assertNotFound();
    }

    public function test_een_verkeerd_token_geeft_404_en_geen_403(): void
    {
        config(['deploy.token' => 'het-echte-token']);

        $this->postJson('/api/deploy/migrate', [], ['Authorization' => 'Bearer fout'])
            ->assertNotFound();
    }

    public function test_zonder_authorization_header_geeft_404(): void
    {
        config(['deploy.token' => 'het-echte-token']);

        $this->postJson('/api/deploy/migrate')->assertNotFound();
    }

    public function test_met_het_juiste_token_draait_de_taak(): void
    {
        config(['deploy.token' => 'het-echte-token']);

        $this->postJson('/api/deploy/clear', [], ['Authorization' => 'Bearer het-echte-token'])
            ->assertOk()
            ->assertJson(['task' => 'clear', 'exit_code' => 0]);
    }

    public function test_enkel_de_toegelaten_taken_bestaan(): void
    {
        config(['deploy.token' => 'het-echte-token']);

        $this->postJson('/api/deploy/db:wipe', [], ['Authorization' => 'Bearer het-echte-token'])
            ->assertNotFound();
    }

    public function test_de_reset_weigert_zolang_hij_niet_toegelaten_is(): void
    {
        config(['deploy.token' => 'het-echte-token', 'deploy.allow_reset' => false]);

        $this->postJson('/api/deploy/reset', [], ['Authorization' => 'Bearer het-echte-token'])
            ->assertNotFound();
    }

    public function test_de_reset_weigert_als_de_snapshot_ontbreekt(): void
    {
        config([
            'deploy.token' => 'het-echte-token',
            'deploy.allow_reset' => true,
            'deploy.snapshot' => 'app/private/bestaat-niet.sql.gz',
        ]);

        $this->postJson('/api/deploy/reset', [], ['Authorization' => 'Bearer het-echte-token'])
            ->assertStatus(409);
    }
}
