<?php

namespace App\Observers;

use App\Models\Game;
use App\Services\SeasonCalculator;

/**
 * Herberekent de tussenstand automatisch na elke wijziging aan een game; de
 * SeasonCalculator bepaalt zelf welke speeldagen meetellen (enkel volledig
 * ingevulde). Vervangt de handmatige "bereken tussenstand"-actie uit de legacy-API.
 */
class GameObserver
{
    private const SCORE_COLUMNS = [
        'set1_home', 'set1_away',
        'set2_home', 'set2_away',
        'set3_home', 'set3_away',
    ];

    public function __construct(private readonly SeasonCalculator $calculator)
    {
    }

    public function saved(Game $game): void
    {
        if ($game->wasRecentlyCreated || $game->wasChanged(self::SCORE_COLUMNS)) {
            $this->calculator->calculate($game->round->season);
        }
    }

    public function deleted(Game $game): void
    {
        $this->calculator->calculate($game->round->season);
    }
}
