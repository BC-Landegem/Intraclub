<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerSeasonStatistic extends Model
{
    protected $fillable = [
        'season_id',
        'player_id',
        'base_points',
        'sets_played',
        'sets_won',
        'points_played',
        'points_won',
        'rounds_present',
        'games_played',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
