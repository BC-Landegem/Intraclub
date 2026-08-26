<?php

namespace App\Http\Resources;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Speler in lijstvorm.
 *
 * @mixin Player
 */
class PlayerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstName' => $this->first_name,
            'name' => $this->last_name,
            'gender' => $this->gender->apiValue(),
            'doubleRanking' => $this->double_ranking,
            'playsCompetition' => (int) $this->plays_competition,
            'member' => (int) $this->is_member,
        ];
    }
}
