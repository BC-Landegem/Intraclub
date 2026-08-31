<?php

namespace App\Filament\Resources\ArchiveSeasons;

use App\Filament\Resources\ArchiveSeasons\Pages\ListArchiveSeasons;
use App\Filament\Resources\ArchiveSeasons\Pages\ViewArchiveSeason;
use App\Filament\Resources\ArchiveSeasons\RelationManagers\RoundsRelationManager;
use App\Filament\Resources\ArchiveSeasons\RelationManagers\StandingsRelationManager;
use App\Filament\Resources\ArchiveSeasons\Tables\ArchiveSeasonsTable;
use App\Models\Archive\ArchiveSeason;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Seizoenen van vóór het huidige systeem (2009-2023). Alleen-lezen: deze data komt
 * uit `intraclub:import-archive` en wordt niet in de app bewerkt.
 */
class ArchiveSeasonResource extends Resource
{
    protected static ?string $model = ArchiveSeason::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Archief';

    protected static ?string $modelLabel = 'oud seizoen';

    protected static ?string $pluralModelLabel = 'oude seizoenen';

    protected static ?string $navigationLabel = 'Seizoenen';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return ArchiveSeasonsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            StandingsRelationManager::class,
            RoundsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArchiveSeasons::route('/'),
            'view' => ViewArchiveSeason::route('/{record}'),
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
