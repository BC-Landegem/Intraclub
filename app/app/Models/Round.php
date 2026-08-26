<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Round extends Model
{
    protected $fillable = [
        'season_id',
        'number',
        'date',
        'average_absent',
        'is_calculated',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'is_calculated' => 'boolean',
        ];
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function games(): HasMany
    {
        return $this->hasMany(Game::class);
    }

    public function playerStatistics(): HasMany
    {
        return $this->hasMany(PlayerRoundStatistic::class);
    }
}
