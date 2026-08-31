<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * De plaats in het algemene klassement na deze speeldag, bevroren op het moment
 * van berekenen.
 *
 * Zonder deze kolom werd de historische rank bij elke opvraging opnieuw geteld
 * uit de gemiddeldes. Dat gaf twee problemen: de telling in RankingHistory nam
 * ook niet-leden mee (RankingService niet), en zodra iemand de club verliet
 * schoof ieders rank in álle voorbije speeldagen op. "Vijfde na speeldag 17"
 * werd zo een cijfer dat volgend seizoen anders kon zijn.
 *
 * Vanaf nu: de klassementen op /api/rankings zijn de stand van vandaag en mogen
 * meebewegen met het ledenbestand; player_round_statistics.rank is wat het toen
 * was en verandert nooit meer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_round_statistics', function (Blueprint $table) {
            $table->unsignedSmallInteger('rank')->nullable()->after('average');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('player_round_statistics', function (Blueprint $table) {
            $table->dropColumn('rank');
        });
    }

    /**
     * Reconstructie voor de speeldagen die al berekend zijn. Het ledenbestand van
     * nu is de beste benadering die we hebben van het ledenbestand van toen; wie
     * ondertussen gestopt is, telt dus niet mee. Vanaf de eerstvolgende
     * berekening schrijft SeasonCalculator de rank zelf en is er niets meer te
     * reconstrueren.
     */
    private function backfill(): void
    {
        foreach (DB::table('rounds')->orderBy('id')->pluck('id') as $roundId) {
            $ids = DB::table('player_round_statistics')
                ->join('players', 'players.id', '=', 'player_round_statistics.player_id')
                ->where('player_round_statistics.round_id', $roundId)
                ->whereNotNull('player_round_statistics.average')
                ->where('players.is_member', true)
                ->orderByDesc('player_round_statistics.average')
                ->pluck('player_round_statistics.id')
                ->all();

            if ($ids === []) {
                continue;
            }

            // Eén update per speeldag in plaats van één per speler: op de hosting
            // draait dit via /api/deploy/migrate, en dat mag geen minuten duren.
            // De id's komen uit de databank en zijn integers, dus veilig te
            // interpoleren — bindings in een CASE zou de query alleen langer maken.
            $cases = '';
            foreach ($ids as $position => $id) {
                $cases .= ' WHEN '.(int) $id.' THEN '.($position + 1);
            }

            DB::statement(
                'UPDATE player_round_statistics SET `rank` = CASE id'.$cases.' END WHERE id IN ('.implode(',', array_map('intval', $ids)).')'
            );
        }
    }
};
