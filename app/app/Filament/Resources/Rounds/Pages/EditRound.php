<?php

namespace App\Filament\Resources\Rounds\Pages;

use App\Filament\Resources\Rounds\RoundResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditRound extends EditRecord
{
    protected static string $resource = RoundResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
