<?php

namespace App\Http\Resources;

use App\Models\Round;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Speeldag in lijstvorm, inclusief het aantal gespeelde games.
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
            'number' => $this->number,
            'averageAbsent' => $this->average_absent === null ? null : round($this->average_absent, 2),
            'date' => $this->date->format('Y-m-d'),
            'calculated' => (int) $this->is_calculated,
            'matches' => (int) ($this->games_count ?? $this->games()->count()),
        ];
    }
}
