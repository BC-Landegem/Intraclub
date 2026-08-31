<?php

namespace App\Services;

use App\Models\Season;
use Illuminate\Support\Facades\DB;

/**
 * Maakt een nieuw seizoen aan inclusief de startstatistieken per speler:
 * iedereen begint met basispunten volgens de eindstand van het vorige seizoen
 * (laatste plaats 19.0000, elke plaats hoger +0.0001).
 *
 * 1:1 port van intraclub\managers\SeasonManager::create uit de legacy-API.
 */
class SeasonCreator
{
    public function __construct(private readonly RankingService $rankingService)
    {
    }

    public function create(string $name): Season
    {
        return DB::transaction(function () use ($name): Season {
            $ranking = $this->rankingService->get(categories: [RankingService::CATEGORY_GENERAL]);

            $season = Season::create(['name' => $name]);

            $basePoints = 19.000;
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
