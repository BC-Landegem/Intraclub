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
            $ranking = $this->rankingService->get(categories: [RankingService::CATEGORY_GENERAL]);

            $season = Season::create([
                'name' => $name,
                'points_per_set' => $pointsPerSet,
            ]);

            $basePoints = $pointsPerSet->startingBasePoints();
            foreach (array_reverse($ranking['categories'][RankingService::CATEGORY_GENERAL]) as $rankedPlayer) {
                $season->playerStatistics()->create([
                    'player_id' => $rankedPlayer['id'],
                    'base_points' => $basePoints,
                ]);
                $basePoints += 0.0001;
            }

            return $season;
        });
    }
}
