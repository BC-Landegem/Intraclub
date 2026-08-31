<?php

namespace App\Http\Resources;

use App\Models\Season;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Seizoen. Welk seizoen het lopende is staat niet per rij maar één keer in
 * `meta.current_season_id` — een `is_current` per rij zou per seizoen opnieuw
 * moeten opzoeken welk het jongste is.
 *
 * `players_count` is de lengte van de eindstand, niet het aantal seizoensrijen:
 * wie op de laatste berekende speeldag geen gemiddelde heeft, staat niet in die
 * stand. Het staat daarom niet op een `withCount()` maar op een waarde die de
 * controller erbij zet.
 *
 * @mixin Season
 */
class SeasonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'points_per_set' => $this->points_per_set->value,
            'rounds_count' => $this->whenCounted('rounds'),
            'players_count' => (int) $this->players_count,
        ];
    }
}
