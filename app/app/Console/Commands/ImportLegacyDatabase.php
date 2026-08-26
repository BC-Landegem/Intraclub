<?php

namespace App\Console\Commands;

use App\Enums\Gender;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class ImportLegacyDatabase extends Command
{
    protected $signature = 'intraclub:import-legacy
        {--force : Sla de bevestiging over}';

    protected $description = 'Importeert de oude intraclub-database (connectie "legacy") in het nieuwe schema. Herhaalbaar: bestaande data in de doeltabellen wordt eerst gewist. ID\'s blijven behouden.';

    private const TARGET_TABLES = [
        'player_season_statistics',
        'player_round_statistics',
        'games',
        'rounds',
        'seasons',
        'players',
    ];

    public function handle(): int
    {
        $legacy = DB::connection('legacy');

        if (! $this->option('force') && ! $this->confirm(
            sprintf(
                'Dit wist alle data in [%s] van database "%s" en importeert opnieuw vanaf "%s". Doorgaan?',
                implode(', ', self::TARGET_TABLES),
                DB::getDatabaseName(),
                $legacy->getDatabaseName(),
            )
        )) {
            return self::FAILURE;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (self::TARGET_TABLES as $table) {
                DB::table($table)->truncate();
            }

            $this->importPlayers($legacy);
            $this->importSeasons($legacy);
            $this->importRounds($legacy);
            $this->importGames($legacy);
            $this->importPlayerRoundStatistics($legacy);
            $this->importPlayerSeasonStatistics($legacy);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        foreach (array_reverse(self::TARGET_TABLES) as $table) {
            $this->info(sprintf('%-25s %5d rijen', $table, DB::table($table)->count()));
        }

        return self::SUCCESS;
    }

    private function importPlayers(ConnectionInterface $legacy): void
    {
        $this->copy($legacy, 'player', 'players', fn (object $row): array => [
            'id' => $row->Id,
            'first_name' => $row->Firstname,
            'last_name' => $row->Name,
            'gender' => $row->Gender === 'Woman' ? Gender::Female->value : Gender::Male->value,
            'birth_date' => $row->BirthDate,
            'double_ranking' => $row->DoubleRanking,
            'plays_competition' => (bool) $row->PlaysCompetition,
            'is_member' => (bool) $row->Member,
        ]);
    }

    private function importSeasons(ConnectionInterface $legacy): void
    {
        $this->copy($legacy, 'season', 'seasons', fn (object $row): array => [
            'id' => $row->Id,
            'name' => $row->Name,
        ]);
    }

    private function importRounds(ConnectionInterface $legacy): void
    {
        $this->copy($legacy, 'round', 'rounds', fn (object $row): array => [
            'id' => $row->Id,
            'season_id' => $row->SeasonId,
            'number' => $row->Number,
            'date' => $row->Date,
            'average_absent' => $row->AverageAbsent,
            'is_calculated' => (bool) $row->Calculated,
        ]);
    }

    private function importGames(ConnectionInterface $legacy): void
    {
        $this->copy($legacy, 'match', 'games', fn (object $row): array => [
            'id' => $row->Id,
            'round_id' => $row->RoundId,
            'home_player1_id' => $row->Player1Id,
            'home_player2_id' => $row->Player2Id,
            'away_player1_id' => $row->Player3Id,
            'away_player2_id' => $row->Player4Id,
            'set1_home' => $row->Set1Home,
            'set1_away' => $row->Set1Away,
            'set2_home' => $row->Set2Home,
            'set2_away' => $row->Set2Away,
            'set3_home' => $row->Set3Home,
            'set3_away' => $row->Set3Away,
        ]);
    }

    private function importPlayerRoundStatistics(ConnectionInterface $legacy): void
    {
        $this->copy($legacy, 'playerroundstatistic', 'player_round_statistics', fn (object $row): array => [
            'round_id' => $row->RoundId,
            'player_id' => $row->PlayerId,
            'is_present' => (bool) $row->Present,
            'is_drawn_out' => (bool) $row->DrawnOut,
            'average' => $row->Average,
        ], ['RoundId', 'PlayerId']);
    }

    private function importPlayerSeasonStatistics(ConnectionInterface $legacy): void
    {
        $this->copy($legacy, 'playerseasonstatistic', 'player_season_statistics', fn (object $row): array => [
            'id' => $row->Id,
            'season_id' => $row->SeasonId,
            'player_id' => $row->PlayerId,
            'base_points' => $row->BasePoints,
            'sets_played' => $row->SetsPlayed,
            'sets_won' => $row->SetsWon,
            'points_played' => $row->PointsPlayed,
            'points_won' => $row->PointsWon,
            'rounds_present' => $row->RoundsPresent,
            'games_played' => $row->MatchesPlayed,
        ]);
    }

    private function copy(ConnectionInterface $legacy, string $sourceTable, string $targetTable, callable $map, array $orderBy = ['Id']): void
    {
        $now = now();

        $query = $legacy->table($sourceTable);
        foreach ($orderBy as $column) {
            $query->orderBy($column);
        }

        $query->chunk(500, function ($rows) use ($targetTable, $map, $now): void {
            DB::table($targetTable)->insert(
                $rows->map(fn (object $row): array => $map($row) + [
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        });
    }
}


