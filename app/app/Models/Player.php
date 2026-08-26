<?php

namespace App\Models;

use App\Enums\Gender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Player extends Model
{
    public const VETERAN_AGE = 45;

    protected $fillable = [
        'first_name',
        'last_name',
        'gender',
        'birth_date',
        'double_ranking',
        'plays_competition',
        'is_member',
    ];

    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'birth_date' => 'date',
            'plays_competition' => 'boolean',
            'is_member' => 'boolean',
        ];
    }

    public function roundStatistics(): HasMany
    {
        return $this->hasMany(PlayerRoundStatistic::class);
    }

    public function seasonStatistics(): HasMany
    {
        return $this->hasMany(PlayerSeasonStatistic::class);
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->first_name} {$this->last_name}");
    }

    protected function isVeteran(): Attribute
    {
        return Attribute::get(fn (): bool => $this->birth_date->age >= self::VETERAN_AGE);
    }

    protected function isRecreant(): Attribute
    {
        return Attribute::get(fn (): bool => ! $this->plays_competition);
    }

    public function scopeMembers(Builder $query): Builder
    {
        return $query->where('is_member', true);
    }

    public function games(): Builder
    {
        return Game::query()->where(
            fn (Builder $query) => $query
                ->orWhere('home_player1_id', $this->id)
                ->orWhere('home_player2_id', $this->id)
                ->orWhere('away_player1_id', $this->id)
                ->orWhere('away_player2_id', $this->id)
        );
    }
}
