<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bewaakt `?include=` voor de hele publieke API. Wat een route mag meesturen
 * staat op de route zelf: `->defaults('include', ['games'])`.
 *
 * Een onbekende include geeft 422, en dat geldt óók voor routes die er geen
 * kennen. Stil negeren was hier de duurste soort fout: wie `?include=attendances`
 * op een lijst zette kreeg de kale lijst terug en haalde de rest dan alsnog per
 * speler op — honderden requests zonder dat iets waarschuwde.
 *
 * De geparseerde lijst gaat als request-attribuut mee, zodat de controller hem
 * niet nog een tweede keer uit de querystring hoeft te pluizen (ParsesIncludes).
 */
class ValidateIncludes
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = (array) ($request->route()?->defaults['include'] ?? []);

        $raw = $request->query('include', '');

        // `?include[]=games` mag ook: een querystring kan altijd een array opleveren,
        // en dat mag geen 500 geven op een endpoint dat verder niets fout doet.
        $requested = array_values(array_filter(array_map(
            fn ($naam): string => is_string($naam) ? trim($naam) : '',
            is_array($raw) ? $raw : explode(',', (string) $raw)
        )));

        $unknown = array_diff($requested, $allowed);

        abort_if($unknown !== [], 422, $allowed === []
            ? 'Deze route ondersteunt geen ?include=.'
            : 'Onbekende include: '.implode(', ', $unknown).'. Toegelaten: '.implode(', ', $allowed).'.');

        $request->attributes->set('includes', $requested);

        return $next($request);
    }
}
