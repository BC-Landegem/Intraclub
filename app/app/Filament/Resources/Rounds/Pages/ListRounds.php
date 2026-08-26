<?php

namespace App\Filament\Resources\Rounds\Pages;

use App\Filament\Resources\Rounds\RoundResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRounds extends ListRecords
{
    protected static string $resource = RoundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
