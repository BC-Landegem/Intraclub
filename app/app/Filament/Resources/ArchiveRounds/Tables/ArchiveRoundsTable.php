<?php

namespace App\Filament\Resources\ArchiveRounds\Tables;

use App\Models\Archive\ArchiveSeason;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ArchiveRoundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('season')->withCount('games'))
            ->columns([
                TextColumn::make('season.name')
                    ->label('Seizoen')
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Speeldag')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('date')
                    ->label('Datum')
                    ->date('d-m-Y')
                    ->sortable(),
                TextColumn::make('games_count')
                    ->label('Wedstrijden')
                    ->numeric(),
            ])
            ->filters([
                SelectFilter::make('archive_season_id')
                    ->label('Seizoen')
                    ->options(fn (): array => ArchiveSeason::query()->orderByDesc('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
