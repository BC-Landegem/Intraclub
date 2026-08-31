<?php

namespace App\Filament\Resources\Rounds\Tables;

use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RoundsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('date', 'desc')
            ->columns([
                TextColumn::make('season.name')
                    ->label('Seizoen')
                    ->sortable(),
                TextColumn::make('number')
                    ->label('Speeldag')
                    ->sortable(),
                TextColumn::make('date')
                    ->label('Datum')
                    ->date('d-m-Y')
                    ->sortable(),
                TextColumn::make('games_count')
                    ->label('Games')
                    ->counts('games'),
                IconColumn::make('is_calculated')
                    ->label('Berekend')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('season_id')
                    ->label('Seizoen')
                    ->relationship('season', 'name'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
