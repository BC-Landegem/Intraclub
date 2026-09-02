<?php

namespace Tests\Support;

use App\Enums\PointsPerSet;

/**
 * De twee puntenschalen van een seizoen, met de scores en verwachte gemiddeldes
 * die de tests voor beide gebruiken.
 *
 * Tot 15 is de 15-11 mapping van de oude 21-15; tot 21 is het historische format.
 */
final readonly class SeasonFormat
{
    public function __construct(public PointsPerSet $pointsPerSet) {}

    /** @return array<string, array{0: self}> */
    public static function provider(): array
    {
        return [
            'tot 15' => [new self(PointsPerSet::Fifteen)],
            'tot 21' => [new self(PointsPerSet::TwentyOne)],
        ];
    }

    public function value(): int
    {
        return $this->pointsPerSet->value;
    }

    public function win(): int
    {
        return $this->value();
    }

    public function lose(): int
    {
        return match ($this->pointsPerSet) {
            PointsPerSet::Fifteen => 11,
            PointsPerSet::TwentyOne => 15,
        };
    }

    public function loseClose(): int
    {
        return match ($this->pointsPerSet) {
            PointsPerSet::Fifteen => 13,
            PointsPerSet::TwentyOne => 18,
        };
    }

    public function loseDeuce(): int
    {
        return match ($this->pointsPerSet) {
            PointsPerSet::Fifteen => 14,
            PointsPerSet::TwentyOne => 19,
        };
    }

    public function extensionWin(): int
    {
        return match ($this->pointsPerSet) {
            PointsPerSet::Fifteen => 24,
            PointsPerSet::TwentyOne => 30,
        };
    }

    public function extensionLose(): int
    {
        return match ($this->pointsPerSet) {
            PointsPerSet::Fifteen => 22,
            PointsPerSet::TwentyOne => 29,
        };
    }

    public function startingBasePoints(): float
    {
        return $this->pointsPerSet->startingBasePoints();
    }

    public function basePoints(int $index): float
    {
        return $this->startingBasePoints() + $index / 10;
    }

    /** @return array<string, int|null> */
    public function straightSets(bool $complete = true): array
    {
        $scores = [
            'set1_home' => $this->win(),
            'set1_away' => $this->lose(),
        ];

        if ($complete) {
            $scores['set2_home'] = $this->win();
            $scores['set2_away'] = $this->lose();
            $scores['set3_home'] = $this->win();
            $scores['set3_away'] = $this->lose();
        } else {
            $scores['set2_home'] = null;
            $scores['set2_away'] = null;
            $scores['set3_home'] = null;
            $scores['set3_away'] = null;
        }

        return $scores;
    }

    /** @return array<string, int|null> */
    public function firstSetOnly(): array
    {
        return [
            'set1_home' => $this->win(),
            'set1_away' => $this->lose(),
            'set2_home' => null,
            'set2_away' => null,
            'set3_home' => null,
            'set3_away' => null,
        ];
    }

    public function winnerDayScore(): float
    {
        return (float) $this->win();
    }

    public function otherDayScore(): float
    {
        return match ($this->pointsPerSet) {
            PointsPerSet::Fifteen => 12.33,
            PointsPerSet::TwentyOne => 17.0,
        };
    }

    public function absentAverage(): float
    {
        return (float) $this->lose();
    }

    public function pointsWonStraight(): int
    {
        return $this->win() * 3;
    }

    public function pointsConcededStraight(): int
    {
        return $this->lose() * 3;
    }

    /** Eindgemiddelde van speler 2 na twee speeldagen in RecordsApiTest. */
    public function bestSeasonAverage(): float
    {
        return match ($this->pointsPerSet) {
            PointsPerSet::Fifteen => 13.84,
            PointsPerSet::TwentyOne => 19.07,
        };
    }

    /** Gemiddelde van speler 2 na speeldag 1 in RecordsApiTest. */
    public function climbFromAverage(): float
    {
        return match ($this->pointsPerSet) {
            PointsPerSet::Fifteen => 13.27,
            PointsPerSet::TwentyOne => 18.1,
        };
    }
}
