<?php

namespace App\Filament\Resources\ArchivePlayers;

use App\Filament\Resources\ArchivePlayers\Pages\ListArchivePlayers;
use App\Filament\Resources\ArchivePlayers\Pages\ViewArchivePlayer;
use App\Filament\Resources\ArchivePlayers\RelationManagers\SeasonStatisticsRelationManager;
use App\Filament\Resources\ArchivePlayers\Tables\ArchivePlayersTable;
use App\Models\Archive\ArchivePlayer;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Spelers uit de oude jaargangen. Wie nog lid is, is gekoppeld aan zijn huidige
 * spelersfiche; de rest bestaat enkel nog in het archief. Alleen-lezen.
 */
class ArchivePlayerResource extends Resource
{
    protected static ?string $model = ArchivePlayer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Archief';

    protected static ?string $modelLabel = 'oude speler';

    protected static ?string $pluralModelLabel = 'oude spelers';

    protected static ?string $navigationLabel = 'Spelers';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('full_name')->label('Naam'),
            TextEntry::make('gender')->label('Geslacht')->placeholder('—'),
            TextEntry::make('ranking')->label('Klassement destijds')->placeholder('—'),
            TextEntry::make('player.full_name')
                ->label('Huidige spelersfiche')
                ->placeholder('niet meer in het ledenbestand'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return ArchivePlayersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            SeasonStatisticsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArchivePlayers::route('/'),
            'view' => ViewArchivePlayer::route('/{record}'),
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
