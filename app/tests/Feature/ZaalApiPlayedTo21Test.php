<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;

class ZaalApiPlayedTo21Test extends ZaalApiTest
{
    protected static function pointsPerSet(): PointsPerSet
    {
        return PointsPerSet::TwentyOne;
    }
}
