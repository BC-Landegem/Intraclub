<?php

namespace App\Observers;

use App\Models\Game;
use App\Services\SeasonCalculator;

/**
 * Herberekent de tussenstand automatisch zodra een score wordt ingevoerd,
 * gewijzigd of een game wordt verwijderd (vervangt de handmatige
 * "bereken tussenstand"-actie uit de legacy-API).
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
        $scoresEntered = $game->wasRecentlyCreated
            ? collect(self::SCORE_COLUMNS)->contains(fn (string $column): bool => $game->{$column} !== null)
            : $game->wasChanged(self::SCORE_COLUMNS);

        if ($scoresEntered) {
            $this->recalculate($game);
        }
    }

    public function deleted(Game $game): void
    {
        $this->recalculate($game);
    }

    private function recalculate(Game $game): void
    {
        $this->calculator->calculate($game->round->season);
    }
}
