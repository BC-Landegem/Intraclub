<?php

namespace App\Filament\Resources\Seasons;

use App\Enums\PointsPerSet;
use App\Filament\Resources\Seasons\Pages\ManageSeasons;
use App\Models\Season;
use App\Services\SeasonCalculator;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SeasonResource extends Resource
{
    protected static ?string $model = Season::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $modelLabel = 'seizoen';

    protected static ?string $pluralModelLabel = 'seizoenen';

    protected static ?string $navigationLabel = 'Seizoenen';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Naam')
                    ->placeholder('2026 - 2027')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(50),
                Select::make('points_per_set')
                    ->label('Sets tot')
                    ->options(PointsPerSet::class)
                    ->default(PointsPerSet::Fifteen)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Naam')
                    ->searchable(),
                TextColumn::make('points_per_set')
                    ->label('Sets tot')
                    ->badge(),
                TextColumn::make('rounds_count')
                    ->label('Speeldagen')
                    ->counts('rounds'),
                TextColumn::make('player_statistics_count')
                    ->label('Spelers')
                    ->counts('playerStatistics'),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (Season $record): void {
                        app(SeasonCalculator::class)->calculate($record);
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSeasons::route('/'),
        ];
    }
}
