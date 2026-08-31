<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;

class RecordsApiPlayedTo21Test extends RecordsApiTest
{
    protected static function pointsPerSet(): PointsPerSet
    {
        return PointsPerSet::TwentyOne;
    }
}
