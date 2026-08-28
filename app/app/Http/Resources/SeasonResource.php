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
            'rounds_count' => $this->whenCounted('rounds'),
            'players_count' => $this->whenCounted('playerStatistics'),
        ];
    }
}
