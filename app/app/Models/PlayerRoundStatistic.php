<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerRoundStatistic extends Model
{
    protected $fillable = [
        'round_id',
        'player_id',
        'is_present',
        'is_drawn_out',
        'average',
    ];

    protected function casts(): array
    {
        return [
            'is_present' => 'boolean',
            'is_drawn_out' => 'boolean',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
