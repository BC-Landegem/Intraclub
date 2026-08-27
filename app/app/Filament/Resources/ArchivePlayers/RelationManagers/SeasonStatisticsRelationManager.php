<?php

namespace App\Filament\Resources\ArchivePlayers\RelationManagers;

use App\Filament\Resources\ArchiveSeasons\ArchiveSeasonResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SeasonStatisticsRelationManager extends RelationManager
{
    protected static string $relationship = 'seasonStatistics';

    protected static ?string $title = 'Per seizoen';

    public function table(Table $table): Table
    {
        return $table
            // Sorteren op naam en niet op id: de comp-seizoenen kregen hun id ná de
            // intra-seizoenen, dus op id staat 2009-2010 achteraan.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('season')
                ->join('archive_seasons', 'archive_seasons.id', '=', 'archive_player_season_statistics.archive_season_id')
                ->orderBy('archive_seasons.name')
                ->select('archive_player_season_statistics.*'))
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('season.name')
                    ->label('Seizoen'),
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
                TextColumn::make('base_points')
                    ->label('Basispunten')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—'),
            ])
            ->recordActions([
                Action::make('season')
                    ->label('Eindstand')
                    ->icon('heroicon-o-trophy')
                    ->url(fn ($record): string => ArchiveSeasonResource::getUrl('view', ['record' => $record->archive_season_id])),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
