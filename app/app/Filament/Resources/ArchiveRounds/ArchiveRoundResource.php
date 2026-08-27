<?php

namespace App\Filament\Resources\ArchiveRounds;

use App\Filament\Resources\ArchiveRounds\Pages\ListArchiveRounds;
use App\Filament\Resources\ArchiveRounds\Pages\ViewArchiveRound;
use App\Filament\Resources\ArchiveRounds\RelationManagers\GamesRelationManager;
use App\Filament\Resources\ArchiveRounds\Tables\ArchiveRoundsTable;
use App\Models\Archive\ArchiveRound;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Speeldagen uit de oude jaargangen, met hun uitslagen. Alleen-lezen.
 */
class ArchiveRoundResource extends Resource
{
    protected static ?string $model = ArchiveRound::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string|UnitEnum|null $navigationGroup = 'Archief';

    protected static ?string $modelLabel = 'oude speeldag';

    protected static ?string $pluralModelLabel = 'oude speeldagen';

    protected static ?string $navigationLabel = 'Speeldagen';

    protected static ?int $navigationSort = 2;

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('season.name')->label('Seizoen'),
            TextEntry::make('number')->label('Speeldag'),
            TextEntry::make('date')->label('Datum')->date('d-m-Y'),
            TextEntry::make('average_absent')
                ->label('Gemiddelde verliezers')
                ->numeric(decimalPlaces: 2)
                ->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return ArchiveRoundsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            GamesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArchiveRounds::route('/'),
            'view' => ViewArchiveRound::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
