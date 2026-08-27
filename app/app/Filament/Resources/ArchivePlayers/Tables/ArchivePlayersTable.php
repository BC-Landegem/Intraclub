<?php

namespace App\Filament\Resources\ArchivePlayers\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ArchivePlayersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_name')
            ->modifyQueryUsing(fn ($query) => $query->with('player')->withCount('seasonStatistics'))
            ->columns([
                TextColumn::make('first_name')
                    ->label('Voornaam')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('last_name')
                    ->label('Achternaam')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('gender')
                    ->label('Geslacht')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('ranking')
                    ->label('Klassement destijds')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('season_statistics_count')
                    ->label('Seizoenen')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('player.full_name')
                    ->label('Huidige fiche')
                    ->placeholder('gestopt')
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('still_member')
                    ->label('Nog in het ledenbestand')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('player_id')),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
