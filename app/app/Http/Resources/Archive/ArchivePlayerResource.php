<?php

namespace App\Http\Resources\Archive;

use App\Models\Archive\ArchivePlayer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Speler uit de oude jaargangen. `player_id` is gevuld wanneer die persoon nog in
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
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => trim("{$this->first_name} {$this->last_name}"),
            'gender' => $this->gender,
            'ranking' => $this->ranking,
            'player_id' => $this->player_id,
        ];
    }
}
