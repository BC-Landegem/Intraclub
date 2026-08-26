<?php

namespace App\Http\Resources;

use App\Models\PlayerSeasonStatistic;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Speler met seizoensstatistieken (port van
 * intraclub\common\Utilities::mapToPlayerStatisticsObject).
 *
 * @mixin PlayerSeasonStatistic
 */
class PlayerStatisticsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->player->id,
            'firstName' => $this->player->first_name,
            'name' => $this->player->last_name,
            'statistics' => [
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
                'matches' => [
                    'total' => (int) $this->games_played,
                ],
                'rounds' => [
                    'present' => (int) $this->rounds_present,
                ],
            ],
        ];
    }
}
