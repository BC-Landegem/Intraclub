<?php

namespace App\Exceptions;

use App\Models\Season;
use App\Observers\SeasonObserver;
use RuntimeException;

/**
 * Poging om de puntenschaal van een seizoen te wijzigen dat al een speeldag heeft.
 * Zie {@see SeasonObserver} voor waarom dat niet kan.
 */
class SeasonScaleIsFixed extends RuntimeException
{
    public static function for(Season $season): self
    {
        return new self(
            "De puntenschaal van seizoen '{$season->name}' ligt vast: er staat al een speeldag op."
        );
    }
}
