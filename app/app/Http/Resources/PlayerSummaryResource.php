<?php

namespace App\Http\Resources;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Speler zoals hij in een wedstrijd of aanwezigheidslijst voorkomt: net genoeg
 * om hem te tonen en naar zijn pagina te linken.
 *
 * `bonus_points` staat erbij omdat de speeldagpagina het handicapverschil per
 * duo toont. Let op: die bonus is een afspraak op het terrein (het zwakkere duo
 * begint met een voorsprong), geen berekening in de scores — de opgeslagen
 * setstanden zijn de eindstanden zoals ze gespeeld zijn.
 *
 * @mixin Player
 */
class PlayerSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'bonus_points' => $this->bonus_points,
        ];
    }
}
