<?php

namespace Database\Seeders;

use App\Enums\PointsPerSet;
use App\Models\Player;
use App\Models\Season;
use Illuminate\Database\Seeder;

/**
 * Dertig verzonnen leden voor een lege ontwikkeldatabank, ingeschreven in het
 * lopende seizoen. Bestaande spelers (een productiedump, een tweede seed) blijven
 * met rust.
 */
class DemoPlayersSeeder extends Seeder
{
    public const COUNT = 30;

    public function run(): void
    {
        if (Player::query()->exists()) {
            return;
        }

        $players = Player::factory()->count(self::COUNT)->create();

        $year = now()->month >= 9 ? now()->year : now()->year - 1;
        $season = Season::current() ?? Season::query()->create([
            'name' => sprintf('%d - %d', $year, $year + 1),
            'points_per_set' => PointsPerSet::Fifteen,
        ]);

        $basePoints = $season->points_per_set->startingBasePoints();
        foreach ($players->reverse() as $player) {
            $season->playerStatistics()->create([
                'player_id' => $player->id,
                'base_points' => $basePoints,
            ]);
            $basePoints += 0.0001;
        }
    }
}
