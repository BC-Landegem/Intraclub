<?php

namespace App\Models;

use App\Enums\DrawSystem;
use App\Enums\PointsPerSet;
use App\Observers\SeasonObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[ObservedBy(SeasonObserver::class)]
class Season extends Model
{
    protected $fillable = [
        'name',
        'points_per_set',
        'draw_system',
    ];

    protected $attributes = [
        'points_per_set' => 21,
        'draw_system' => DrawSystem::StrengthGroups->value,
    ];

    protected function casts(): array
    {
        return [
            'points_per_set' => PointsPerSet::class,
            'draw_system' => DrawSystem::class,
        ];
    }

    public function rounds(): HasMany
    {
        return $this->hasMany(Round::class);
    }

    public function playerStatistics(): HasMany
    {
        return $this->hasMany(PlayerSeasonStatistic::class);
    }

    public static function current(): ?self
    {
        return self::query()->orderByDesc('id')->first();
    }
}
