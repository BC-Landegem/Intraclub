<?php

namespace App\Http\Resources;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Speler met zijn tellers voor één seizoen, en op vraag zijn wedstrijden en
 * rankingverloop.
 *
 * De sub-resources bestaan ook los (/players/{player}/games en
 * /players/{player}/ranking-history). `?include=` bestaat omdat de
 * spelerspagina alle drie tegelijk nodig heeft en dat geen drie requests hoeft
 * te zijn.
 *
 * @mixin Player
 */
class PlayerDetailResource extends JsonResource
{
    /** @param array<string, mixed> $extra */
    public function __construct(Player $player, private readonly array $statistics, private readonly array $extra = [])
    {
        parent::__construct($player);
    }

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
            'statistics' => $this->statistics,
        ] + $this->extra;
    }
}
