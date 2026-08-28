<?php

namespace App\Http\Resources;

use App\Models\PlayerSeasonStatistic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Seizoenstellers van één speler.
 *
 * De lijstvorm (/seasons/{season}/statistics) geeft speler én tellers; de
 * spelerpagina heeft de speler al en gebruikt daarom counters() los, zodat het
 * `statistics`-blok op beide plaatsen exact dezelfde velden heeft.
 *
 * @mixin PlayerSeasonStatistic
 */
class PlayerSeasonStatisticResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'player' => new PlayerSummaryResource($this->player),
            'statistics' => $this->counters(),
        ];
    }

    /** @return array<string, mixed> */
    public function counters(): array
    {
        return [
            // Het startpunt van de speler dit seizoen: 19,000 voor wie nieuw is,
            // anders het eindgemiddelde van vorig seizoen met de plaats van toen
            // in de tienduizendsten als scheidsrechter bij gelijke stand.
            'base_points' => $this->base_points === null ? null : round((float) $this->base_points, 4),
            'points' => [
                'won' => (int) $this->points_won,
                'lost' => (int) $this->points_played - (int) $this->points_won,
                'total' => (int) $this->points_played,
            ],
            'sets' => [
                'won' => (int) $this->sets_won,
                'lost' => (int) $this->sets_played - (int) $this->sets_won,
                'total' => (int) $this->sets_played,
            ],
            'games' => [
                'total' => (int) $this->games_played,
            ],
            'rounds' => [
                'present' => (int) $this->rounds_present,
            ],
        ];
    }
}
