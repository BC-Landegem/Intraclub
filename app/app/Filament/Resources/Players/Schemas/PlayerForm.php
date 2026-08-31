<?php

namespace App\Filament\Resources\Players\Schemas;

use App\Enums\Gender;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PlayerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->label('Voornaam')
                    ->required()
                    ->maxLength(100),
                TextInput::make('last_name')
                    ->label('Achternaam')
                    ->required()
                    ->maxLength(100),
                Select::make('gender')
                    ->label('Geslacht')
                    ->options(Gender::class)
                    ->required(),
                DatePicker::make('birth_date')
                    ->label('Geboortedatum')
                    ->required()
                    ->maxDate(now()),
                TextInput::make('double_ranking')
                    ->label('Dubbelklassement (punten)')
                    ->required()
                    ->numeric()
                    ->minValue(0),
                Toggle::make('plays_competition')
                    ->label('Speelt competitie'),
                Toggle::make('is_member')
                    ->label('Lid')
                    ->default(true),
            ]);
    }
}
