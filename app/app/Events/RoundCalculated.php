<?php

namespace App\Events;

use App\Models\Round;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Een speeldag kwam net in de stand: is_calculated ging van false naar true.
 * Afgevuurd door SeasonCalculator ná zijn transactie, één keer per speeldag die
 * in die berekening omsloeg — niet bij elke herberekening van een speeldag die
 * al in de stand zat.
 */
class RoundCalculated
{
    use Dispatchable;

    public function __construct(public readonly Round $round) {}
}
