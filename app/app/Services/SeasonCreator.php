<?php

namespace App\Services;

use App\Enums\PointsPerSet;
use App\Models\Season;
use Illuminate\Support\Facades\DB;

/**
 * Maakt een nieuw seizoen aan inclusief de startstatistieken per speler:
 * iedereen begint met basispunten volgens de eindstand van het vorige seizoen
 * (laatste plaats 14.0000 bij sets tot 15, of 19.0000 bij sets tot 21; elke
 * plaats hoger +0.0001).
 *
 * 1:1 port van intraclub\managers\SeasonManager::create uit de legacy-API.
 */
class SeasonCreator
{
    public function __construct(private readonly RankingService $rankingService) {}

    public function create(string $name, PointsPerSet $pointsPerSet = PointsPerSet::Fifteen): Season
    {
        return DB::transaction(function () use ($name, $pointsPerSet): Season {
            // De eindstand, niet het gepubliceerde klassement: dat zet wie de
            // laatste speeldagen niet meespeelde onderaan, en dan zouden de
            // basispunten afwezigheid bestraffen in plaats van het gemiddelde te
            // volgen. Vóór Season::create(), want dat wordt het lopende seizoen.
            $previous = Season::current();
            $standing = $previous === null ? [] : $this->rankingService->finalStanding($previous);

            $season = Season::create([
                'name' => $name,
                'points_per_set' => $pointsPerSet,
            ]);

            $basePoints = $pointsPerSet->startingBasePoints();
            foreach (array_reverse(array_keys($standing)) as $playerId) {
                $season->playerStatistics()->create([
                    'player_id' => $playerId,
                    'base_points' => $basePoints,
                ]);
                $basePoints += 0.0001;
            }

            return $season;
        });
    }
}
