<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArchiveRound extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(ArchiveSeason::class, 'archive_season_id');
    }

    public function games(): HasMany
    {
        return $this->hasMany(ArchiveGame::class, 'archive_round_id');
    }

    public function playerStatistics(): HasMany
    {
        return $this->hasMany(ArchivePlayerRoundStatistic::class, 'archive_round_id');
    }
}
