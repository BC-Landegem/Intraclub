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
            'averageAbsent' => $this->average_absent,
            'seasonId' => $this->archive_season_id,
            'seasonName' => $this->whenLoaded('season', fn (): string => $this->season->name),
            'games' => $this->whenCounted('games'),
        ];
    }
}
