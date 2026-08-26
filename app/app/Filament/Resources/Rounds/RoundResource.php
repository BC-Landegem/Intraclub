<?php

namespace App\Filament\Resources\Rounds;

use App\Filament\Resources\Rounds\Pages\CreateRound;
use App\Filament\Resources\Rounds\Pages\EditRound;
use App\Filament\Resources\Rounds\Pages\ListRounds;
use App\Filament\Resources\Rounds\Pages\ViewRound;
use App\Filament\Resources\Rounds\Schemas\RoundForm;
use App\Filament\Resources\Rounds\Schemas\RoundInfolist;
use App\Filament\Resources\Rounds\Tables\RoundsTable;
use App\Models\Round;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class RoundResource extends Resource
{
    protected static ?string $model = Round::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $modelLabel = 'speeldag';

    protected static ?string $pluralModelLabel = 'speeldagen';

    protected static ?string $navigationLabel = 'Speeldagen';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return RoundForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return RoundInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoundsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\GamesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRounds::route('/'),
            'create' => CreateRound::route('/create'),
            'view' => ViewRound::route('/{record}'),
            'edit' => EditRound::route('/{record}/edit'),
        ];
    }
}
