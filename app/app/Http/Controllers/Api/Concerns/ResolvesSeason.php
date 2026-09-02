<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Season;
use Illuminate\Http\Request;

/**
 * Elk publiek endpoint werkt op één seizoen. Zonder `?season=` is dat het
 * lopende seizoen; `current` mag ook expliciet, zowel als queryparameter als in
 * het pad (/seasons/current/statistics). Dat laatste volgt /api/me, dat in deze
 * API al hetzelfde doet voor "de huidige gebruiker".
 *
 * Een onbekend seizoen geeft 404 en niet stilzwijgend het lopende: anders lijkt
 * een typefout in een build-script gewoon te werken.
 */
trait ResolvesSeason
{
    protected function seasonFromQuery(Request $request): ?Season
    {
        $value = $request->query('season');

        if ($value === null || $value === '' || $value === 'current') {
            return Season::current();
        }

        return Season::findOrFail((int) $value);
    }

    protected function seasonFromPath(string $value): Season
    {
        if ($value !== 'current') {
            return Season::findOrFail((int) $value);
        }

        $season = Season::current();
        abort_if($season === null, 404, 'Er is nog geen seizoen aangemaakt.');

        return $season;
    }

    /** @return array{id: int, name: string, points_per_set: int}|null */
    protected function seasonMeta(?Season $season): ?array
    {
        return $season === null ? null : [
            'id' => $season->id,
            'name' => $season->name,
            'points_per_set' => $season->points_per_set->value,
        ];
    }
}
