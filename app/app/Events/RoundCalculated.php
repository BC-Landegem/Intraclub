<?php

namespace App\Events;

use App\Models\Round;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Een speeldag kwam net in de stand: is_calculated ging van false naar true.
 * Afgevuurd door SeasonCalculator, één keer per speeldag die in die berekening
 * omsloeg — niet bij elke herberekening van een speeldag die er al in zat.
 *
 * Buiten de transactie van de calculator, maar niet noodzakelijk buiten élke:
 * roept een controller `calculate()` binnen een eigen transactie aan (zoals
 * ZaalController bij het aanmaken van een match), dan is die van de calculator
 * een savepoint en schrijft een luisteraar mee in de buitenste. Vandaag klopt
 * dat vanzelf, want de wachtrij staat op `database` en de job-rij rolt dan mee
 * terug. Verhuist de wachtrij ooit naar Redis, dan moet SendPushMessage
 * `afterCommit` krijgen.
 */
class RoundCalculated
{
    use Dispatchable;

    public function __construct(public readonly Round $round) {}
}
