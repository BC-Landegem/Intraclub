<?php

namespace App\Filament\Resources\Seasons;

use App\Enums\DrawSystem;
use App\Enums\PointsPerSet;
use App\Filament\Resources\Seasons\Pages\ManageSeasons;
use App\Models\Season;
use App\Services\SeasonEncounters;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
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
                // Radio en geen Select: bij twee keuzes staan beide uitleggen meteen in
                // beeld, en `DrawSystem` levert ze zelf via HasDescription. Dat spaart
                // een helperText die de gekozen waarde moest opzoeken.
                //
                // Bewust niet op slot zoals de schaal hierboven: een lotingswissel
                // maakt niets ongeldig, dus een paar avonden proberen mag.
                Radio::make('draw_system')
                    ->label('Loting')
                    ->options(DrawSystem::class)
                    ->default(DrawSystem::StrengthGroups)
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
                TextColumn::make('draw_system')
                    ->label('Loting')
                    ->badge(),
                // De cijfers waarmee de lotingsystemen te vergelijken zijn: gemiddeld
                // aantal verschillende tegenstanders, en de ergste herhaling. Ze staan
                // per seizoen naast elkaar omdat dat de enige zinvolle vergelijking is:
                // op zichzelf zegt "26,5" niets, naast "21,6" wel.
                //
                // Eén kolom en geen twee, want elk van de twee zou dezelfde telling
                // opnieuw doen. Zo blijft het één query per rij zonder cache die langer
                // leeft dan het verzoek.
                TextColumn::make('spread')
                    ->label('Spreiding')
                    ->tooltip('Gemiddeld aantal verschillende tegenstanders per speler, en hoe vaak hetzelfde tweetal elkaar in het slechtste geval tegenkwam.')
                    ->state(function (Season $record): string {
                        $spread = app(SeasonEncounters::class)->spread($record->id);

                        return $spread['players'] === 0
                            ? '—'
                            : sprintf(
                                '%s · max %d×',
                                number_format($spread['averageOpponents'], 1, ',', '.'),
                                $spread['highestRepeat']
                            );
                    }),
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
