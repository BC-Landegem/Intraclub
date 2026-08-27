<?php

namespace App\Filament\Resources\ArchiveSeasons\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ArchiveSeasonsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->withCount(['rounds', 'playerStatistics']))
            ->columns([
                TextColumn::make('name')
                    ->label('Seizoen')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source')
                    ->label('Generatie')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'comp' ? 'comp (2009-2013)' : 'intra (2013-2023)'),
                TextColumn::make('rounds_count')
                    ->label('Speeldagen')
                    ->numeric(),
                TextColumn::make('player_statistics_count')
                    ->label('Spelers in de eindstand')
                    ->numeric()
                    // 2010-2011 werd destijds nooit gearchiveerd: enkel de uitslagen bleven over.
                    ->placeholder('geen eindstand bewaard')
                    ->formatStateUsing(fn (int $state): ?string => $state === 0 ? null : (string) $state),
            ])
            ->filters([
                SelectFilter::make('source')
                    ->label('Generatie')
                    ->options(['comp' => 'comp (2009-2013)', 'intra' => 'intra (2013-2023)']),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
