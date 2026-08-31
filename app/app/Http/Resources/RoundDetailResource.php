<?php

namespace App\Http\Resources;

use App\Models\PlayerRoundStatistic;
use App\Models\Round;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Speeldag met wedstrijden en aanwezigheden.
 *
 * `attendances` bevat elke speler met een statistiekrij voor deze speeldag, dus
 * ook de afwezigen — aanwezig, uitgeloot en afwezig zijn zo in één lijst te
 * lezen. De spelersnaam zit erbij zodat de site geen tweede call naar /players
 * nodig heeft om er iets van te maken.
 *
 * Per rij staat naast het voortschrijdend gemiddelde ook `day_score`: wat deze
 * speeldag voor die speler opbracht. Zonder dat cijfer is de sprong in het
 * gemiddelde op de site niet uit te leggen.
 *
 * @mixin Round
 */
class RoundDetailResource extends JsonResource
{
    /** @param array<int, float|null> $dayScores speler-id => dagscore */
    public function __construct(Round $round, private readonly array $dayScores = [])
    {
        parent::__construct($round);
    }

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
            'attendances' => self::attendances($this->resource, $this->dayScores),
        ];
    }

    /**
     * De aanwezigheden van één speeldag, op naam gesorteerd.
     *
     * Publiek en statisch omdat /rounds?include=attendances precies dezelfde rijen
     * teruggeeft: de vorm hoort op één plaats te staan, anders lopen de twee
     * responses uiteen zodra hier een veld bijkomt.
     *
     * @param  array<int, float|null>  $dayScores  speler-id => dagscore
     * @return Collection<int, array<string, mixed>>
     */
    public static function attendances(Round $round, array $dayScores = []): Collection
    {
        return $round->playerStatistics
            // [sleutel, richting] en geen closures: sortBy() roept een closure aan
            // als vergelijkingsfunctie ($a, $b), niet als sleutelfunctie — met
            // closures bleef deze lijst in databankvolgorde staan.
            ->sortBy([
                ['player.first_name', 'asc'],
                ['player.last_name', 'asc'],
            ])
            ->map(fn (PlayerRoundStatistic $statistic): array => [
                'player' => new PlayerSummaryResource($statistic->player),
                'is_present' => (bool) $statistic->is_present,
                'is_drawn_out' => (bool) $statistic->is_drawn_out,
                'day_score' => self::rounded($dayScores[$statistic->player_id] ?? null),
                'average' => $statistic->average === null ? null : round((float) $statistic->average, 2),
                'rank' => $statistic->rank,
            ])
            ->values();
    }

    private static function rounded(?float $score): ?float
    {
        return $score === null ? null : round($score, 2);
    }
}
