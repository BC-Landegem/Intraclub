<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('E-mailadres')
                    ->searchable(),
                IconColumn::make('is_admin')
                    ->label('Beheer')
                    ->boolean()
                    ->tooltip(fn ($record): string => $record->is_admin
                        ? 'Beheerspaneel en zaal-app'
                        : 'Enkel de zaal-app'),
                TextColumn::make('created_at')
                    ->label('Aangemaakt')
                    ->dateTime('d-m-Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->hidden(fn ($record): bool => $record->id === auth()->id()),
            ]);
    }
}
