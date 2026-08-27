<?php

namespace App\Filament\Resources\ArchiveRounds\Pages;

use App\Filament\Resources\ArchiveRounds\ArchiveRoundResource;
use Filament\Resources\Pages\ViewRecord;

class ViewArchiveRound extends ViewRecord
{
    protected static string $resource = ArchiveRoundResource::class;

    public function getTitle(): string
    {
        return "Speeldag {$this->record->number} — {$this->record->season->name}";
    }
}
