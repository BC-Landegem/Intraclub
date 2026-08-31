<?php

namespace App\Http\Resources;

use App\Models\Round;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Speeldag in lijstvorm.
 *
 * De drie tellingen staan hier omdat de site ze anders zelf afleidt. Dat deed ze
 * met `games * 4`, wat fout is zodra er iemand uitgeloot wordt: bij een oneven
 * aantal aanwezigen speelt niet iedereen mee.
 *
 * @mixin Round
 */
class RoundResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => (int) $this->number,
            'date' => $this->date->format('Y-m-d'),
            'is_calculated' => (bool) $this->is_calculated,
            'average_absent' => $this->average_absent === null ? null : round($this->average_absent, 2),
            'games_count' => (int) ($this->games_count ?? $this->games()->count()),
            'players_present' => (int) ($this->players_present_count
                ?? $this->playerStatistics()->where('is_present', true)->count()),
            'players_drawn_out' => (int) ($this->players_drawn_out_count
                ?? $this->playerStatistics()->where('is_drawn_out', true)->count()),
        ];
    }
}
