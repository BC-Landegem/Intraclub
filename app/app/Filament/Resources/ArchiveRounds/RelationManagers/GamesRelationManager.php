<?php

namespace App\Filament\Resources\ArchiveRounds\RelationManagers;

use App\Models\Archive\ArchiveGame;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Uitslagen van een oude speeldag. Anders dan bij `games` staan de teams hier vast
 * voor de hele match, en ontbreekt de derde set wanneer die niet meer nodig was.
 */
class GamesRelationManager extends RelationManager
{
    protected static string $relationship = 'games';

    protected static ?string $title = 'Uitslagen';

    public function table(Table $table): Table
    {
        return $table
            ->description('Vaste teams, best-of-3: team 1 speelde alle sets tegen team 2. Een ontbrekende derde set betekent dat de match al na twee sets beslist was.')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['team1Player1', 'team1Player2', 'team2Player1', 'team2Player2']))
            ->columns([
                TextColumn::make('team1')
                    ->label('Team 1')
                    ->state(fn (ArchiveGame $record): string => $record->team1Player1->full_name.' + '.$record->team1Player2->full_name)
                    ->weight(fn (ArchiveGame $record): ?string => $record->sets_won[0] > $record->sets_won[1] ? 'bold' : null),
                TextColumn::make('team2')
                    ->label('Team 2')
                    ->state(fn (ArchiveGame $record): string => $record->team2Player1->full_name.' + '.$record->team2Player2->full_name)
                    ->weight(fn (ArchiveGame $record): ?string => $record->sets_won[1] > $record->sets_won[0] ? 'bold' : null),
                TextColumn::make('score')
                    ->label('Sets')
                    ->state(fn (ArchiveGame $record): string => $record->score),
                TextColumn::make('sets_won')
                    ->label('Stand')
                    ->state(fn (ArchiveGame $record): string => implode('-', $record->sets_won))
                    ->badge(),
            ])
            ->paginated([25, 50])
            ->recordActions([])
            ->headerActions([])
            ->toolbarActions([]);
    }
}
