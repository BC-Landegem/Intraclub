<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Een minuut cachen op de publieke, alleen-lezen endpoints.
 *
 * De hosting is shared en de klassementen worden op een speeldagavond door
 * tientallen mensen tegelijk opgevraagd. De service worker van de clubwebsite
 * werkt network-first, dus wie ververst ziet nog steeds de nieuwe stand zodra
 * die er is — dit vangt enkel het herhaald opvragen binnen dezelfde minuut.
 */
class PublicCacheHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->isSuccessful()) {
            $response->headers->set('Cache-Control', 'public, max-age=60');
        }

        return $response;
    }
}
