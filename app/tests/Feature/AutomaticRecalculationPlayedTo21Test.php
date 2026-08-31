<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;

class AutomaticRecalculationPlayedTo21Test extends AutomaticRecalculationTest
{
    protected static function pointsPerSet(): PointsPerSet
    {
        return PointsPerSet::TwentyOne;
    }
}
