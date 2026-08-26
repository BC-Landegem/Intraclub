<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Game extends Model
{
    protected $fillable = [
        'round_id',
        'home_player1_id',
        'home_player2_id',
        'away_player1_id',
        'away_player2_id',
        'set1_home',
        'set1_away',
        'set2_home',
        'set2_away',
        'set3_home',
        'set3_away',
    ];

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function homePlayer1(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'home_player1_id');
    }

    public function homePlayer2(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'home_player2_id');
    }

    public function awayPlayer1(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'away_player1_id');
    }

    public function awayPlayer2(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'away_player2_id');
    }

    protected function isComplete(): Attribute
    {
        return Attribute::get(
            fn (): bool => $this->set1_home !== null && $this->set1_away !== null
                && $this->set2_home !== null && $this->set2_away !== null
        );
    }
}
