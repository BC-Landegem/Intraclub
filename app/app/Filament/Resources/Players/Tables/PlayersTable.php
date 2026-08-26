<?php

namespace App\Filament\Resources\Players\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PlayersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('first_name')
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
                    ->badge(),
                TextColumn::make('birth_date')
                    ->label('Geboortedatum')
                    ->date('d-m-Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('double_ranking')
                    ->label('Dubbelklassement')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('plays_competition')
                    ->label('Competitie')
                    ->boolean(),
                IconColumn::make('is_member')
                    ->label('Lid')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_member')
                    ->label('Lid')
                    ->default(true),
                TernaryFilter::make('plays_competition')
                    ->label('Speelt competitie'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
