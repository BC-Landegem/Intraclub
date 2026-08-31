<?php

namespace App\Models\Archive;

use App\Enums\Gender;
use App\Models\Player;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Speler uit de oude jaargangen. `player_id` wijst naar het huidige ledenbestand
 * wanneer die persoon nog bestaat; is leeg voor wie gestopt is vóór 2023.
 */
class ArchivePlayer extends Model
{
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
        ];
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function seasonStatistics(): HasMany
    {
        return $this->hasMany(ArchivePlayerSeasonStatistic::class);
    }

    public function roundStatistics(): HasMany
    {
        return $this->hasMany(ArchivePlayerRoundStatistic::class);
    }

    /** Getrimd, want een "Onbekende speler" heeft geen voornaam. */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->first_name} {$this->last_name}"));
    }
}
