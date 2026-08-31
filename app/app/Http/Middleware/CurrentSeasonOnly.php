<?php

namespace App\Http\Middleware;

use App\Models\Round;
use App\Models\Season;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * De seizoensgrens van de publieke API: van alles vóór het lopende seizoen blijft
 * publiek enkel de eindstand, de erelijst en de records over.
 *
 * De regel in één zin: één regel uit een eindstand mag altijd, die regels van één
 * persoon bij elkaar zetten mag alleen als die persoon nog lid is. Wat deze
 * middleware tegenhoudt is het eerste deel van de keerzijde daarvan — speeldagen,
 * wedstrijden, aanwezigheden en klassementsverloop van een afgesloten seizoen.
 *
 * Ze hangt per route in routes/api.php en niet op de hele groep, want /rankings en
 * /seasons/{id}/statistics moeten juist wél elk seizoen kunnen tonen: dát zijn de
 * eindstanden. Zo staat de grens in de routetabel te lezen als documentatie.
 *
 * Het seizoen komt van de route zelf: een {round} weet bij welk seizoen hij hoort,
 * de rest gebruikt `?season=` met dezelfde regels als ResolvesSeason (weglaten of
 * `current` = het lopende seizoen, iets onbekends = 404).
 */
class CurrentSeasonOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->seasonId($request) !== Season::current()?->id) {
            return response()->json([
                'message' => 'Van een afgesloten seizoen is publiek enkel de eindstand beschikbaar.',
                'error' => ['code' => 'season_closed'],
            ], 403);
        }

        return $next($request);
    }

    private function seasonId(Request $request): ?int
    {
        $round = $request->route()?->parameter('round');

        if ($round instanceof Round) {
            return (int) $round->season_id;
        }

        $value = $request->query('season');

        if ($value === null || $value === '' || $value === 'current') {
            return Season::current()?->id;
        }

        // Een array of iets anders dan een getal is geen seizoen. Zelfde antwoord
        // als een onbestaand id, zodat een typefout in een build-script opvalt.
        abort_unless(is_scalar($value), 404);

        return Season::findOrFail((int) $value)->id;
    }
}
