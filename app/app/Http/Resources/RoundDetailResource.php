<?php

namespace App\Http\Resources;

use App\Models\Round;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Speeldag met wedstrijden en aanwezigheden, zoals de speeldagpagina en de
 * zaal-app ze verwachten.
 *
 * @mixin Round
 */
class RoundDetailResource extends JsonResource
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
            'matches' => GameResource::collection($this->games),
            'availabilityData' => $this->playerStatistics->map(fn ($statistic): array => [
                'playerId' => $statistic->player_id,
                'present' => (int) $statistic->is_present,
                'drawnOut' => (int) $statistic->is_drawn_out,
                'average' => $statistic->average,
            ])->values(),
        ];
    }
}
