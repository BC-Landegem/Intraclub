<?php

namespace App\Http\Resources;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Speler in lijstvorm.
 *
 * Geboortedatum blijft er bewust af: de site heeft alleen `is_veteran` nodig en
 * dat is de afgeleide die niemands verjaardag publiceert.
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
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'gender' => $this->gender->value,
            'double_ranking' => (int) $this->double_ranking,
            'plays_competition' => (bool) $this->plays_competition,
            'is_veteran' => $this->is_veteran,
            'is_recreant' => $this->is_recreant,
            'is_member' => (bool) $this->is_member,
            'bonus_points' => $this->bonus_points,
        ];
    }
}
