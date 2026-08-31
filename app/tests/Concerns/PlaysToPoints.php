<?php

namespace Tests\Concerns;

use App\Enums\PointsPerSet;
use Tests\Support\SeasonFormat;

trait PlaysToPoints
{
    protected SeasonFormat $format;

    protected static function pointsPerSet(): PointsPerSet
    {
        return PointsPerSet::Fifteen;
    }

    protected function bootFormat(): void
    {
        $this->format = new SeasonFormat(static::pointsPerSet());
    }
}
