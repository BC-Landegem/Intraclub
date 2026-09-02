<?php

namespace App\Filament\Resources\Seasons;

use App\Enums\PointsPerSet;
use App\Filament\Resources\Seasons\Pages\ManageSeasons;
use App\Models\Season;
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
                    ->required()
                    // Zodra er een speeldag staat, ligt de schaal vast: de setstanden
                    // zijn op die schaal gespeeld en omzetten zou ze herinterpreteren.
                    // SeasonObserver bewaakt dat sowieso; dit toont het gewoon.
                    ->disabled(fn (?Season $record): bool => $record?->rounds()->exists() ?? false)
                    ->helperText(fn (?Season $record): ?string => $record?->rounds()->exists()
                        ? 'Ligt vast: dit seizoen heeft al een speeldag.'
                        : null),
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
                // Bewust zonder herberekening: een naamswijziging mag de bevroren stand
                // van een afgesloten seizoen niet aanraken. De enige wijziging die wél
                // gevolgen heeft is de puntenschaal, en die bewaakt SeasonObserver.
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSeasons::route('/'),
        ];
    }
}
