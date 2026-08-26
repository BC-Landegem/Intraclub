<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Rankinggeschiedenis van één speler binnen een seizoen: per berekende speeldag
 * de plaats en het gemiddelde. Port van
 * RankingRepository::getRankingHistoryByPlayerAndSeason uit de legacy-API.
 */
class RankingHistory
{
    /** @return list<array{id: int, number: int, average: float, rank: int}> */
    public function forPlayer(int $playerId, int $seasonId): array
    {
        $rows = DB::table('player_round_statistics as statistic')
            ->join('rounds', 'rounds.id', '=', 'statistic.round_id')
            ->where('rounds.season_id', $seasonId)
            ->where('rounds.is_calculated', true)
            ->whereNotNull('statistic.average')
            ->orderBy('rounds.id')
            ->orderByDesc('statistic.average')
            ->select('statistic.player_id', 'statistic.average', 'statistic.round_id', 'rounds.number')
            ->get();

        $history = [];
        $rank = [];

        foreach ($rows as $row) {
            $rank[$row->round_id] = ($rank[$row->round_id] ?? 0) + 1;

            if ((int) $row->player_id === $playerId) {
                $history[] = [
                    'id' => (int) $row->round_id,
                    'number' => (int) $row->number,
                    'average' => round((float) $row->average, 2),
                    'rank' => $rank[$row->round_id],
                ];
            }
        }

        return $history;
    }
}
