<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArchivePlayerRoundStatistic extends Model
{
    protected $guarded = [];

    public function round(): BelongsTo
    {
        return $this->belongsTo(ArchiveRound::class, 'archive_round_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(ArchivePlayer::class, 'archive_player_id');
    }
}
