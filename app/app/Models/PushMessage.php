<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Een verstuurd (of te versturen) pushbericht, zie de migration voor waarom dit
 * bewaard wordt. `sent_at` is null zolang de job in de wachtrij staat.
 */
class PushMessage extends Model
{
    protected $fillable = [
        'topic',
        'title',
        'body',
        'url',
        'round_id',
        'user_id',
        'recipients',
        'sent_count',
        'expired_count',
        'failed_count',
        'sent_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function round(): BelongsTo
    {
        return $this->belongsTo(Round::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** De JSON die de service worker van de site verwacht. */
    public function payload(): string
    {
        $payload = [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'topic' => $this->topic,
        ];

        if ($tag = config("push.topics.{$this->topic}.tag")) {
            $payload['tag'] = $tag;
        }

        return json_encode(array_filter($payload, fn ($value) => $value !== null), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
