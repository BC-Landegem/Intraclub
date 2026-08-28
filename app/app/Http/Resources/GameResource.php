<?php

namespace App\Http\Resources;

use App\Models\Game;
use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Eén wedstrijd: vier spelers, drie sets met roterende duo's.
 *
 * De opstelling per set staat hier en niet in de frontend. Wie samen speelt is
 * domeinlogica (set 1: P1+P2 vs P3+P4 — set 2: P1+P3 vs P2+P4 — set 3: P1+P4 vs
 * P2+P3) en hoort op één plek te staan; de site rendert alleen nog.
 *
 * De duo's verwijzen met `player_ids` naar de spelers in `players` in plaats van
 * die objecten zes keer te herhalen.
 *
 * @mixin Game
 */
class GameResource extends JsonResource
{
    /**
     * Slots per set: [thuisduo, uitduo]. "home" is het eerste duo van die set.
     */
    private const LINE_UPS = [
        1 => [[1, 2], [3, 4]],
        2 => [[1, 3], [2, 4]],
        3 => [[1, 4], [2, 3]],
    ];

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $players = [
            1 => $this->player1,
            2 => $this->player2,
            3 => $this->player3,
            4 => $this->player4,
        ];

        return [
            'id' => $this->id,
            'round' => [
                'id' => (int) $this->round_id,
                'number' => (int) $this->round->number,
                'date' => $this->round->date->format('Y-m-d'),
            ],
            'players' => PlayerSummaryResource::collection(array_values($players)),
            'is_complete' => $this->is_complete,
            'sets' => $this->sets($players),
        ];
    }

    /**
     * @param  array<int, Player>  $players
     * @return list<array<string, mixed>>
     */
    private function sets(array $players): array
    {
        $sets = [];

        foreach (self::LINE_UPS as $number => [$homeSlots, $awaySlots]) {
            $home = $this->{"set{$number}_home"};
            $away = $this->{"set{$number}_away"};
            $isPlayed = $home !== null && $away !== null;

            $sets[] = [
                'number' => $number,
                'is_played' => $isPlayed,
                // Gelijkspel telt als winst voor het uitduo: zo rekende de legacy-API
                // en zo staan de gemiddeldes in de databank. Zie GameStatistics.
                'winner' => $isPlayed ? ((int) $home > (int) $away ? 'home' : 'away') : null,
                'home' => $this->side($players, $homeSlots, $home),
                'away' => $this->side($players, $awaySlots, $away),
            ];
        }

        return $sets;
    }

    /**
     * @param  array<int, Player>  $players
     * @param  array<int, int>  $slots
     * @return array<string, mixed>
     */
    private function side(array $players, array $slots, ?int $score): array
    {
        return [
            'player_ids' => array_map(fn (int $slot): int => $players[$slot]->id, $slots),
            'score' => $score === null ? null : (int) $score,
            'bonus' => array_sum(array_map(fn (int $slot): int => $players[$slot]->bonus_points, $slots)),
        ];
    }
}
