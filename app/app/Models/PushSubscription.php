<?php

namespace App\Models;

use Database\Factories\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Een toestel dat pushberichten wil ontvangen: het endpoint bij de pushdienst
 * van zijn browser, de twee sleutels om het bericht voor dat toestel te
 * versleutelen, en de onderwerpen waarop het intekende. Niets over wie het is.
 */
class PushSubscription extends Model
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory;

    protected $fillable = [
        'endpoint_hash',
        'endpoint',
        'p256dh',
        'auth',
        'topics',
    ];

    protected function casts(): array
    {
        return [
            'topics' => 'array',
        ];
    }

    public static function hashEndpoint(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    public static function findByEndpoint(string $endpoint): ?self
    {
        return self::query()->where('endpoint_hash', self::hashEndpoint($endpoint))->first();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForTopic(Builder $query, string $topic): Builder
    {
        return $query->whereJsonContains('topics', $topic);
    }
}
