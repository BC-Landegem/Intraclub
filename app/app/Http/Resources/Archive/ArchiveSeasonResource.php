<?php

namespace App\Http\Resources\Archive;

use App\Models\Archive\ArchiveSeason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Gearchiveerd seizoen.
 *
 * `players_count` is de lengte van de eindstand, niet het aantal seizoensrijen:
 * wie nooit een speeldag speelde stond toen ook nergens. Het staat daarom niet
 * op een `withCount()` maar op een waarde die de controller erbij zet.
 *
 * @mixin ArchiveSeason
 */
class ArchiveSeasonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'source' => $this->source,
            'rounds_count' => $this->whenCounted('rounds'),
            'players_count' => (int) $this->players_count,
        ];
    }
}
