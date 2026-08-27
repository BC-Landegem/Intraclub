<?php

namespace App\Http\Resources\Archive;

use App\Models\Archive\ArchiveSeason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Gearchiveerd seizoen.
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
            'rounds' => $this->whenCounted('rounds'),
            'players' => $this->whenCounted('playerStatistics'),
        ];
    }
}
