<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchivePlayerSeasonStatistic extends Model
{
    protected $guarded = [];

    public function season(): BelongsTo
    {
        return $this->belongsTo(ArchiveSeason::class, 'archive_season_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(ArchivePlayer::class, 'archive_player_id');
    }
}
