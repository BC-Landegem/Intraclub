<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rankinggeschiedenis van één speler binnen een seizoen: per berekende speeldag de
 * plaats, het voortschrijdend gemiddelde en de dagscore van die speeldag.
 *
 * De plaats wordt hier niet geteld maar gelezen uit
 * player_round_statistics.rank, die SeasonCalculator bevriest op het moment van
 * berekenen. Dat lost twee dingen op: de telling nam vroeger ook niet-leden mee
 * (waardoor de rank hier kon afwijken van diezelfde rank in het klassement), en
 * ze werd bij elke opvraging opnieuw gedaan over álle spelers van het seizoen.
 *
 * `day_score` maakt de formule navolgbaar: het gemiddelde na speeldag N is het
 * gemiddelde van de basispunten en alle dagscores tot en met N. Zonder dat cijfer
 * kan niemand op de site narekenen waar zijn gemiddelde vandaan komt.
 */
class RankingHistory
{
    public function __construct(private readonly DayScores $dayScores) {}

    /**
     * @return list<array{round_id: int, number: int, date: string, average: float, day_score: float|null, rank: int}>
     */
    public function forPlayer(int $playerId, int $seasonId): array
    {
        $dagscores = $this->dayScores->forPlayerSeason($playerId, $seasonId);

        return DB::table('player_round_statistics as statistic')
            ->join('rounds', 'rounds.id', '=', 'statistic.round_id')
            ->where('rounds.season_id', $seasonId)
            ->where('rounds.is_calculated', true)
            ->where('statistic.player_id', $playerId)
            ->whereNotNull('statistic.rank')
            ->orderBy('rounds.number')
            ->get(['statistic.round_id', 'rounds.number', 'rounds.date', 'statistic.average', 'statistic.rank'])
            ->map(function (object $row) use ($dagscores): array {
                $dagscore = $dagscores[$row->round_id] ?? null;

                return [
                    'round_id' => (int) $row->round_id,
                    'number' => (int) $row->number,
                    'date' => Carbon::parse($row->date)->format('Y-m-d'),
                    'average' => round((float) $row->average, 2),
                    'day_score' => $dagscore === null ? null : round($dagscore, 2),
                    'rank' => (int) $row->rank,
                ];
            })
            ->all();
    }
}
