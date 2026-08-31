<?php

namespace App\Filament\Resources\Rounds\Schemas;

use App\Models\Season;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class RoundForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('season_id')
                    ->label('Seizoen')
                    ->relationship('season', 'name')
                    ->default(fn (): ?int => Season::current()?->id)
                    ->required(),
                TextInput::make('number')
                    ->label('Speeldagnummer')
                    ->default(fn (): int => (int) (Season::current()?->rounds()->max('number') ?? 0) + 1)
                    ->required()
                    ->numeric()
                    ->minValue(1),
                DatePicker::make('date')
                    ->label('Datum')
                    ->default(now())
                    ->required(),
            ]);
    }
}
