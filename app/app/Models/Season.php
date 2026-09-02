<?php

namespace App\Models;

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
    ];

    protected $attributes = [
        'points_per_set' => 21,
    ];

    protected function casts(): array
    {
        return [
            'points_per_set' => PointsPerSet::class,
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
