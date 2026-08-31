<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Wedstrijd uit de oude jaargangen: vaste teams in best-of-3. Team 1 speelt alle
 * sets tegen team 2 — anders dan `Game`, waar de teams per set roteren. De derde
 * set is leeg wanneer de match al na twee sets beslist was.
 */
class ArchiveGame extends Model
{
    protected $guarded = [];

    public function round(): BelongsTo
    {
        return $this->belongsTo(ArchiveRound::class, 'archive_round_id');
    }

    public function team1Player1(): BelongsTo
    {
        return $this->belongsTo(ArchivePlayer::class, 'team1_player1_id');
    }

    public function team1Player2(): BelongsTo
    {
        return $this->belongsTo(ArchivePlayer::class, 'team1_player2_id');
    }

    public function team2Player1(): BelongsTo
    {
        return $this->belongsTo(ArchivePlayer::class, 'team2_player1_id');
    }

    public function team2Player2(): BelongsTo
    {
        return $this->belongsTo(ArchivePlayer::class, 'team2_player2_id');
    }

    /** Aantal gewonnen sets per team, als [team1, team2]. */
    protected function setsWon(): Attribute
    {
        return Attribute::get(function (): array {
            $team1 = 0;
            $team2 = 0;

            foreach ([['set1_home', 'set1_away'], ['set2_home', 'set2_away'], ['set3_home', 'set3_away']] as [$thuis, $uit]) {
                if ($this->{$thuis} === null || $this->{$uit} === null) {
                    continue;
                }
                if ($this->{$thuis} > $this->{$uit}) {
                    $team1++;
                } else {
                    $team2++;
                }
            }

            return [$team1, $team2];
        });
    }

    /** Setstanden als "21-14, 18-21, 21-19"; een onbespeelde derde set valt weg. */
    protected function score(): Attribute
    {
        return Attribute::get(function (): string {
            $sets = [];

            foreach ([['set1_home', 'set1_away'], ['set2_home', 'set2_away'], ['set3_home', 'set3_away']] as [$thuis, $uit]) {
                if ($this->{$thuis} !== null && $this->{$uit} !== null) {
                    $sets[] = "{$this->{$thuis}}-{$this->{$uit}}";
                }
            }

            return implode(', ', $sets);
        });
    }
}
