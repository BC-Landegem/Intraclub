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

    /**
     * De JSON die de service worker van de site verwacht.
     *
     * Elk bericht draagt een tag, ook een clubbericht dat er in config/push.php
     * geen vaste heeft. Mislukt een job halverwege, dan stuurt de retry naar
     * iedereen opnieuw; zonder tag hangt hetzelfde bericht dan een tweede keer
     * naast het eerste. Met `club-12` vervangt het zichzelf, terwijl twee
     * verschillende clubberichten wel naast elkaar blijven staan.
     */
    public function payload(): string
    {
        $payload = [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'topic' => $this->topic,
            'tag' => config("push.topics.{$this->topic}.tag") ?? "{$this->topic}-{$this->id}",
        ];

        return json_encode(array_filter($payload, fn ($value) => $value !== null), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
