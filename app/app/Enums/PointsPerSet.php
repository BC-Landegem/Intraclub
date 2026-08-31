<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PointsPerSet: int implements HasLabel
{
    case Fifteen = 15;
    case TwentyOne = 21;

    public function getLabel(): string
    {
        return match ($this) {
            self::Fifteen => 'Tot 15',
            self::TwentyOne => 'Tot 21',
        };
    }

    /**
     * Startpunten voor de laatste in de ranking bij een nieuw seizoen.
     * Elke plaats hoger krijgt +0.0001.
     */
    public function startingBasePoints(): float
    {
        return match ($this) {
            self::Fifteen => 14.0,
            self::TwentyOne => 19.0,
        };
    }
}
