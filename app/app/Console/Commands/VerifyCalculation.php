<?php

namespace App\Console\Commands;

use App\Models\Season;
use App\Services\SeasonCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Regressietest voor de geporte rekenlogica: vergelijkt de door de legacy-API opgeslagen
 * statistieken (zoals geïmporteerd uit de dump) met wat onze SeasonCalculator berekent.
 *
 * Draai dit na een verse `intraclub:import-legacy`. Het command herberekent het seizoen
 * en rapporteert elke afwijking t.o.v. de geïmporteerde waarden.
 */
class VerifyCalculation extends Command
{
    protected $signature = 'intraclub:verify-calculation
        {--season= : Seizoen-id (standaard: meest recente)}
        {--tolerance=0.000000001 : Maximaal toegelaten verschil voor doubles}';

    protected $description = 'Herbereken een seizoen en diff het resultaat tegen de geïmporteerde legacy-waarden.';

    public function handle(SeasonCalculator $calculator): int
    {
        $season = $this->option('season')
            ? Season::findOrFail((int) $this->option('season'))
            : Season::query()->orderByDesc('id')->firstOrFail();
        $tolerance = (float) $this->option('tolerance');

        $this->info("Seizoen: {$season->name} (id {$season->id})");

        $before = $this->snapshot($season);
        $calculator->calculate($season);
        $after = $this->snapshot($season);

        $mismatches = 0;
        $maxDelta = 0.0;

        foreach ($before as $dataset => $rows) {
            foreach ($rows as $key => $values) {
                if (! isset($after[$dataset][$key])) {
                    $this->error("[$dataset] $key: rij verdwenen na herberekening");
                    $mismatches++;

                    continue;
                }
                foreach ($values as $column => $legacyValue) {
                    $newValue = $after[$dataset][$key][$column];
                    $delta = abs((float) $legacyValue - (float) $newValue);
                    $maxDelta = max($maxDelta, $delta);
                    if ($delta > $tolerance) {
                        $this->error(sprintf('[%s] %s.%s: legacy %.10f ≠ nieuw %.10f (Δ %.2e)', $dataset, $key, $column, $legacyValue, $newValue, $delta));
                        $mismatches++;
                    }
                }
            }
            $added = array_diff_key($after[$dataset], $rows);
            foreach (array_keys($added) as $key) {
                $this->error("[$dataset] $key: nieuwe rij die niet in de legacy-data zat");
                $mismatches++;
            }
            $this->line(sprintf('%-25s %5d rijen vergeleken', $dataset, count($rows)));
        }

        $this->newLine();
        $this->line(sprintf('Grootste afwijking: %.3e', $maxDelta));

        if ($mismatches > 0) {
            $this->error("REGRESSIE: {$mismatches} verschillen. Herstel de data met intraclub:import-legacy.");

            return self::FAILURE;
        }

        $this->info('OK: herberekening is identiek aan de legacy-uitkomst.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, array<string, float|int|null>>>
     */
    private function snapshot(Season $season): array
    {
        $rounds = DB::table('rounds')
            ->where('season_id', $season->id)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (object $row) => ["round:{$row->id}" => [
                'average_absent' => $row->average_absent,
                'is_calculated' => $row->is_calculated,
            ]])
            ->all();

        $roundStatistics = DB::table('player_round_statistics')
            ->join('rounds', 'rounds.id', '=', 'player_round_statistics.round_id')
            ->where('rounds.season_id', $season->id)
            ->orderBy('player_round_statistics.id')
            ->select('player_round_statistics.*')
            ->get()
            ->mapWithKeys(fn (object $row) => ["round:{$row->round_id},player:{$row->player_id}" => [
                'average' => $row->average,
                'is_present' => $row->is_present,
                'is_drawn_out' => $row->is_drawn_out,
            ]])
            ->all();

        $seasonStatistics = DB::table('player_season_statistics')
            ->where('season_id', $season->id)
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (object $row) => ["player:{$row->player_id}" => [
                'base_points' => $row->base_points,
                'sets_played' => $row->sets_played,
                'sets_won' => $row->sets_won,
                'points_played' => $row->points_played,
                'points_won' => $row->points_won,
                'rounds_present' => $row->rounds_present,
                'games_played' => $row->games_played,
            ]])
            ->all();

        return [
            'rounds' => $rounds,
            'player_round_statistics' => $roundStatistics,
            'player_season_statistics' => $seasonStatistics,
        ];
    }
}
