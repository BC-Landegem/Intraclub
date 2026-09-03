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

    /**
     * Het plafond op een verlenging: hoger dan dit kan een setstand niet gaan,
     * want op cap-1 gelijk beslist het volgende punt.
     */
    public function cap(): int
    {
        return match ($this) {
            self::Fifteen => 21,
            self::TwentyOne => 30,
        };
    }

    /**
     * Kan deze setstand bestaan?
     *
     * Een set gaat tot het setmaximum met minstens twee punten verschil. Staat het
     * op één punt verschil vanaf het setmaximum, dan wordt doorgespeeld tot iemand
     * twee punten voorstaat — tot aan de cap, waar het volgende punt beslist. Dat
     * geeft precies drie vormen: doel-tegen-hoogstens-doel-min-2, boven het doel
     * exact twee verschil, en op de cap ook één verschil.
     *
     * Beide leeg is een set die nog niet gespeeld is en dus geldig. Eén van beide
     * leeg is dat nooit: een setstand is een paar.
     */
    public function allowsSet(?int $home, ?int $away): bool
    {
        if ($home === null || $away === null) {
            return $home === null && $away === null;
        }

        if ($home === $away || $home < 0 || $away < 0) {
            return false;
        }

        $winner = max($home, $away);
        $loser = min($home, $away);

        if ($winner === $this->value) {
            return $loser <= $this->value - 2;
        }

        if ($winner > $this->value && $winner < $this->cap()) {
            return $loser === $winner - 2;
        }

        return $winner === $this->cap() && $loser >= $this->cap() - 2;
    }

    /** De regel in één zin, voor wie een geweigerde stand ingetikt heeft. */
    public function setRule(): string
    {
        return "Sets gaan tot {$this->value} met minstens 2 punten verschil, verlenging tot maximum {$this->cap()}.";
    }
}
