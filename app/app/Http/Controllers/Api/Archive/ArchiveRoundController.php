<?php

namespace App\Http\Controllers\Api\Archive;

use App\Http\Controllers\Controller;
use App\Http\Resources\Archive\ArchiveGameResource;
use App\Http\Resources\Archive\ArchiveRoundResource;
use App\Models\Archive\ArchiveRound;
use Illuminate\Http\Request;

/**
 * Gearchiveerde speeldagen met hun uitslagen.
 */
class ArchiveRoundController extends Controller
{
    /** @return array<string, mixed> */
    public function show(Request $request, ArchiveRound $round): array
    {
        $round->load('season');

        $games = $round->games()
            ->with(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2'])
            ->orderBy('id')
            ->get();

        return [
            // resolve() en niet toArray(): dat laatste laat voorwaardelijke velden
            // (whenCounted, whenLoaded) als lege waarden in de response staan.
            'round' => (new ArchiveRoundResource($round))->resolve($request),
            'games' => ArchiveGameResource::collection($games)->resolve($request),
        ];
    }
}
