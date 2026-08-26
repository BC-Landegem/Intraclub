<?php

namespace App\Filament\Resources\Rounds\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class RoundInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('season.name')
                    ->label('Seizoen'),
                TextEntry::make('number')
                    ->label('Speeldag'),
                TextEntry::make('date')
                    ->label('Datum')
                    ->date('d-m-Y'),
                IconEntry::make('is_calculated')
                    ->label('Berekend')
                    ->boolean(),
            ]);
    }
}
