<?php

namespace App\Http\Resources\Archive;

use App\Models\Archive\ArchiveRound;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Gearchiveerde speeldag.
 *
 * @mixin ArchiveRound
 */
class ArchiveRoundResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'date' => $this->date->format('Y-m-d'),
            'average_absent' => $this->average_absent,
            'season_id' => $this->archive_season_id,
            'season_name' => $this->whenLoaded('season', fn (): string => $this->season->name),
            'games_count' => $this->whenCounted('games'),
        ];
    }
}
