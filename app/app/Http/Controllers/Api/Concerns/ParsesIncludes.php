<?php

namespace App\Http\Controllers\Api\Concerns;

use Illuminate\Http\Request;

/**
 * `?include=games,ranking_history` — komma-gescheiden sub-resources die mee in
 * de response mogen.
 *
 * Een onbekende naam geeft 422 met de toegelaten lijst erbij. Stil negeren zou
 * een typefout in een build-script laten lijken op "die speler heeft geen
 * wedstrijden".
 */
trait ParsesIncludes
{
    /**
     * @param  list<string>  $allowed
     * @return list<string>
     */
    protected function includes(Request $request, array $allowed): array
    {
        $raw = (string) $request->query('include', '');

        if (trim($raw) === '') {
            return [];
        }

        $includes = array_values(array_filter(array_map('trim', explode(',', $raw))));
        $unknown = array_diff($includes, $allowed);

        abort_if(
            $unknown !== [],
            422,
            'Onbekende include: '.implode(', ', $unknown).'. Toegelaten: '.implode(', ', $allowed).'.'
        );

        return $includes;
    }
}
