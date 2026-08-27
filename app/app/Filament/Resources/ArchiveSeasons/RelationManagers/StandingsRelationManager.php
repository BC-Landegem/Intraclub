<?php

namespace App\Filament\Resources\ArchiveSeasons\RelationManagers;

use App\Models\Archive\ArchiveRound;
use App\Models\Archive\ArchiveSeason;
use App\Services\Legacy\ArchiveStandings;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * De eindstand van het seizoen zoals het toenmalige systeem ze toonde.
 *
 * Het klassementsgetal zat in beide generaties ergens anders: `intra_*` hield per
 * speeldag een voortschrijdend gemiddelde bij (de stand is die van de laatste
 * speeldag), `comp_*` bewaarde enkel een eindtotaal per seizoen.
 */
class StandingsRelationManager extends RelationManager
{
    protected static string $relationship = 'playerStatistics';

    protected static ?string $title = 'Eindstand';

    public function table(Table $table): Table
    {
        $laatsteSpeeldag = app(ArchiveStandings::class)->laatsteSpeeldag($this->getOwnerRecord());

        return $table
            ->heading($this->heading($laatsteSpeeldag))
            ->emptyStateHeading('Geen eindstand bewaard')
            ->emptyStateDescription('Van dit seizoen zijn enkel de uitslagen overgeleverd, geen klassement.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('player')
                ->addSelect(['round_average' => DB::table('archive_player_round_statistics')
                    ->whereColumn('archive_player_id', 'archive_player_season_statistics.archive_player_id')
                    ->where('archive_round_id', $laatsteSpeeldag?->id ?? 0)
                    ->select('average')
                    ->limit(1),
                ]))
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('COALESCE(round_average, final_points) IS NULL')
                ->orderByRaw('COALESCE(round_average, final_points) DESC'))
            ->columns([
                TextColumn::make('player.full_name')
                    ->label('Speler')
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('round_average')
                    ->label('Gemiddelde')
                    ->state(fn ($record): ?float => $record->round_average ?? $record->final_points)
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—')
                    ->weight('bold'),
                TextColumn::make('base_points')
                    ->label('Basispunten')
                    ->numeric(decimalPlaces: 2)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('rounds_present')
                    ->label('Speeldagen')
                    ->numeric(),
                TextColumn::make('sets_won')
                    ->label('Sets')
                    ->state(fn ($record): string => "{$record->sets_won} / {$record->sets_played}"),
                TextColumn::make('points_won')
                    ->label('Punten')
                    ->state(fn ($record): string => "{$record->points_won} / {$record->points_played}"),
                TextColumn::make('games_won')
                    ->label('Matchen')
                    ->state(fn ($record): string => "{$record->games_won} / {$record->games_played}"),
            ])
            ->paginated([25, 50, 100])
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([]);
    }

    /**
     * De datum hoort er enkel bij als er ook een stand ís: voor 2010-2011 zou hij
     * anders een klassement suggereren dat nooit bewaard werd.
     */
    private function heading(?ArchiveRound $laatsteSpeeldag): string
    {
        if ($laatsteSpeeldag === null || ! $this->getOwnerRecord()->playerStatistics()->exists()) {
            return 'Eindstand';
        }

        return 'Eindstand na de speeldag van '.$laatsteSpeeldag->date->format('d-m-Y');
    }

    /** @param ArchiveSeason $ownerRecord */
    public static function canViewForRecord($ownerRecord, string $pageClass): bool
    {
        return true;
    }
}
