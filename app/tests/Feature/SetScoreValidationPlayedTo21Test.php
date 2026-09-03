<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;

class SetScoreValidationPlayedTo21Test extends SetScoreValidationTest
{
    protected static function pointsPerSet(): PointsPerSet
    {
        return PointsPerSet::TwentyOne;
    }
}
