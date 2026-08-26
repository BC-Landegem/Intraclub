<?php

namespace App\Models;

use App\Observers\GameObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eén game = 3 sets met roterende teams onder 4 spelers:
 * set 1: (P1,P2) vs (P3,P4) — set 2: (P1,P3) vs (P2,P4) — set 3: (P1,P4) vs (P2,P3).
 */
#[ObservedBy(GameObserver::class)]
class Game extends Model
{
    protected $fillable = [
        'round_id',
        'player1_id',
        'player2_id',
        'player3_id',
        'player4_id',
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

    public function player1(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player1_id');
    }

    public function player2(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player2_id');
    }

    public function player3(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player3_id');
    }

    public function player4(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'player4_id');
    }

    /** @return array<int, int> De vier speler-id's in vaste volgorde. */
    public function playerIds(): array
    {
        return [$this->player1_id, $this->player2_id, $this->player3_id, $this->player4_id];
    }

    protected function isComplete(): Attribute
    {
        return Attribute::get(
            fn (): bool => $this->set1_home !== null && $this->set1_away !== null
                && $this->set2_home !== null && $this->set2_away !== null
        );
    }
}
