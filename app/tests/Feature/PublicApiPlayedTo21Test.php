<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;

class PublicApiPlayedTo21Test extends PublicApiTest
{
    protected static function pointsPerSet(): PointsPerSet
    {
        return PointsPerSet::TwentyOne;
    }
}
