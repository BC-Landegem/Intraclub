<?php

namespace App\Http\Resources\Archive;

use App\Models\Archive\ArchivePlayer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Speler uit de oude jaargangen. `playerId` is gevuld wanneer die persoon nog in
 * het huidige ledenbestand staat, zodat de site beide geschiedenissen kan tonen.
 *
 * @mixin ArchivePlayer
 */
class ArchivePlayerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstName' => $this->first_name,
            'name' => $this->last_name,
            'gender' => $this->gender,
            'ranking' => $this->ranking,
            'playerId' => $this->player_id,
        ];
    }
}
