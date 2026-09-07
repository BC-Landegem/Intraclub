<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Een minuut cachen op de publieke, alleen-lezen endpoints. *
 * Voorlopig uitgeschakeld
 **/
class PublicCacheHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // if ($request->isMethod('GET') && $response->isSuccessful() && ! $this->fromTheHall($request)) {
        //     $response->headers->set('Cache-Control', 'public, max-age=60');
        // }

        return $response;
    }

    /**
     * Komt dit verzoek uit de zaal-app?
     *
     * Aan de sessiecookie te zien, en niet aan `$request->user()`: deze routes
     * staan bewust buiten de `web`-groep, dus er is geen sessie gestart en er is
     * dus ook geen gebruiker om naar te vragen. Een sessie starten voor élke
     * websitebezoeker om dit te weten te komen kost meer dan het oplevert.
     *
     * De cookie is precies genoeg. Hij wordt enkel door deze app gezet, enkel na
     * een aanmelding, en de clubwebsite staat op een ander domein en stuurt hem
     * dus nooit mee. En zou iemand hem verzinnen, dan is het ergste gevolg dat
     * één verzoek niet uit de cache komt.
     */
    private function fromTheHall(Request $request): bool
    {
        return $request->hasCookie(config('session.cookie'));
    }
}
