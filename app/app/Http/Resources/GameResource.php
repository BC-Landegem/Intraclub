<?php

namespace App\Http\Resources;

use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wedstrijdvorm zoals de publieke site ze verwacht (port van
 * intraclub\common\Utilities::mapToMatchObject).
 *
 * @mixin Game
 */
class GameResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstPlayer' => $this->playerPayload('player1'),
            'secondPlayer' => $this->playerPayload('player2'),
            'thirdPlayer' => $this->playerPayload('player3'),
            'fourthPlayer' => $this->playerPayload('player4'),
            'firstSet' => ['home' => (int) $this->set1_home, 'away' => (int) $this->set1_away],
            'secondSet' => ['home' => (int) $this->set2_home, 'away' => (int) $this->set2_away],
            'thirdSet' => ['home' => (int) $this->set3_home, 'away' => (int) $this->set3_away],
            'round' => [
                'id' => (int) $this->round_id,
                'number' => (int) $this->round->number,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function playerPayload(string $relation): array
    {
        $player = $this->{$relation};

        return [
            'id' => $player->id,
            'firstName' => $player->first_name,
            'name' => $player->last_name,
        ];
    }
}
