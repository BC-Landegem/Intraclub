<?php

namespace App\Filament\Resources\Seasons\Pages;

use App\Enums\PointsPerSet;
use App\Filament\Resources\Seasons\SeasonResource;
use App\Models\Season;
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
                ->modalDescription('Elke speler start met basispunten volgens de eindstand van het huidige seizoen (laatste plaats 14,0000 bij sets tot 15, of 19,0000 bij sets tot 21; elke plaats hoger +0,0001).')
                ->using(function (array $data): Season {
                    $pointsPerSet = $data['points_per_set'] ?? PointsPerSet::Fifteen;
                    if (! $pointsPerSet instanceof PointsPerSet) {
                        $pointsPerSet = PointsPerSet::from((int) $pointsPerSet);
                    }

                    return app(SeasonCreator::class)->create($data['name'], $pointsPerSet);
                }),
        ];
    }
}
