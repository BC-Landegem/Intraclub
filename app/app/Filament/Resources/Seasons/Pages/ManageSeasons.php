<?php

namespace App\Filament\Resources\Seasons\Pages;

use App\Filament\Resources\Seasons\SeasonResource;
use App\Services\SeasonCreator;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSeasons extends ManageRecords
{
    protected static string $resource = SeasonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nieuw seizoen')
                ->modalDescription('Elke speler start met basispunten volgens de eindstand van het huidige seizoen (laatste plaats 19.0000, elke plaats hoger +0.0001).')
                ->using(fn (array $data): \App\Models\Season => app(SeasonCreator::class)->create($data['name'])),
        ];
    }
}
