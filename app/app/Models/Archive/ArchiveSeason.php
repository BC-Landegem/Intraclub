<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ArchiveSeason extends Model
{
    protected $guarded = [];

    public function rounds(): HasMany
    {
        return $this->hasMany(ArchiveRound::class);
    }

    public function playerStatistics(): HasMany
    {
        return $this->hasMany(ArchivePlayerSeasonStatistic::class);
    }
}
