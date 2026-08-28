<?php

namespace App\Http\Resources;

use App\Models\PlayerRoundStatistic;
use App\Models\Round;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Speeldag met wedstrijden en aanwezigheden.
 *
 * `attendances` bevat elke speler met een statistiekrij voor deze speeldag, dus
 * ook de afwezigen — aanwezig, uitgeloot en afwezig zijn zo in één lijst te
 * lezen. De spelersnaam zit erbij zodat de site geen tweede call naar /players
 * nodig heeft om er iets van te maken.
 *
 * @mixin Round
 */
class RoundDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $statistics = $this->playerStatistics;

        return [
            'id' => $this->id,
            'number' => (int) $this->number,
            'date' => $this->date->format('Y-m-d'),
            'is_calculated' => (bool) $this->is_calculated,
            'average_absent' => $this->average_absent === null ? null : round($this->average_absent, 2),
            'season' => [
                'id' => (int) $this->season_id,
                'name' => $this->season->name,
            ],
            'games_count' => $this->games->count(),
            'players_present' => $statistics->where('is_present', true)->count(),
            'players_drawn_out' => $statistics->where('is_drawn_out', true)->count(),
            'games' => GameResource::collection($this->games),
            'attendances' => $statistics
                ->sortBy([
                    fn (PlayerRoundStatistic $statistic): string => $statistic->player->first_name,
                    fn (PlayerRoundStatistic $statistic): string => $statistic->player->last_name,
                ])
                ->map(fn (PlayerRoundStatistic $statistic): array => [
                    'player' => new PlayerSummaryResource($statistic->player),
                    'is_present' => (bool) $statistic->is_present,
                    'is_drawn_out' => (bool) $statistic->is_drawn_out,
                    'average' => $statistic->average === null ? null : round((float) $statistic->average, 2),
                    'rank' => $statistic->rank,
                ])
                ->values(),
        ];
    }
}
