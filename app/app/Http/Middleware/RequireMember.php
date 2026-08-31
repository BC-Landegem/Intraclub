<?php

namespace App\Http\Middleware;

use App\Models\Player;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * De andere helft van de seizoensgrens: een spelersfiche zet de regels van één
 * persoon bij elkaar, en dat mag enkel zolang die persoon lid is.
 *
 * Namen blijven wél staan in een uitslag — de eindstand van 2023-2024 toont alle
 * 96 deelnemers, ook wie ondertussen gestopt is, want een erelijst waar winnaars
 * uit wegvallen is geen erelijst. Wat dichtgaat is het dossier.
 *
 * Het antwoord is bewust vast en noemt geen naam: wie de fiche van een niet-lid
 * opvraagt hoort niet alsnog te weten wie daar stond. 404 blijft voor "bestaat
 * niet" — dat geeft de route-binding al vóór deze middleware aan de beurt komt.
 */
class RequireMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $player = $request->route()?->parameter('player');

        if (! $player instanceof Player || ! $player->is_member) {
            return response()->json([
                'message' => 'Deze speler is geen lid meer van de club.',
                'error' => ['code' => 'not_a_member'],
            ], 403);
        }

        return $next($request);
    }
}
