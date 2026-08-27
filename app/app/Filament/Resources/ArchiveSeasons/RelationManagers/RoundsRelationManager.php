<?php

namespace App\Filament\Resources\ArchiveSeasons\RelationManagers;

use App\Filament\Resources\ArchiveRounds\ArchiveRoundResource;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RoundsRelationManager extends RelationManager
{
    protected static string $relationship = 'rounds';

    protected static ?string $title = 'Speeldagen';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('date')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('games'))
            ->columns([
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
                TextColumn::make('average_absent')
                    ->label('Gemiddelde verliezers')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Uitslagen')
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record): string => ArchiveRoundResource::getUrl('view', ['record' => $record])),
            ])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
