<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\PlayerSeasonStatistic;
use App\Models\Season;
use Database\Seeders\DemoPlayersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoPlayersSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_het_zet_dertig_leden_in_het_lopende_seizoen(): void
    {
        $this->seed(DemoPlayersSeeder::class);

        $this->assertSame(DemoPlayersSeeder::COUNT, Player::query()->members()->count());
        $this->assertSame(DemoPlayersSeeder::COUNT, PlayerSeasonStatistic::query()->count());
        $this->assertNotNull(Season::current());
    }

    public function test_een_tweede_run_doet_niets(): void
    {
        $this->seed(DemoPlayersSeeder::class);
        $this->seed(DemoPlayersSeeder::class);

        $this->assertSame(DemoPlayersSeeder::COUNT, Player::query()->count());
    }
}
