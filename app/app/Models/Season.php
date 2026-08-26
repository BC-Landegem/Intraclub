<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Season extends Model
{
    protected $fillable = [
        'name',
    ];

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
