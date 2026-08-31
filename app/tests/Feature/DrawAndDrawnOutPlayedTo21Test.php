<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;

class DrawAndDrawnOutPlayedTo21Test extends DrawAndDrawnOutTest
{
    protected static function pointsPerSet(): PointsPerSet
    {
        return PointsPerSet::TwentyOne;
    }
}
