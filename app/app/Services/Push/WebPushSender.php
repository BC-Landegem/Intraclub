<?php

namespace App\Services\Push;

use App\Models\PushMessage;
use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * Verstuurt één PushMessage naar alle toestellen die op zijn onderwerp
 * intekenden, en werkt het logboek bij.
 *
 * Twee dingen die uit de code zelf niet af te lezen zijn:
 *
 * - Een pushdienst die met 404 of 410 antwoordt zegt dat het abonnement niet
 *   meer bestaat: de browser trok het in, of de gebruiker zette berichten uit in
 *   zijn browserinstellingen in plaats van op de site. Die rij gaat er dan uit.
 *   Dit is ook het pad waarlangs een verzonnen endpoint verdwijnt: de dienst
 *   kent het niet en zegt dat bij het eerste bericht.
 * - De HTTP-client is injecteerbaar zodat de tests een pushdienst kunnen
 *   naspelen zonder netwerk; zonder binding zoekt WebPush zelf Guzzle.
 */
class WebPushSender
{
    public function __construct(private readonly ?ClientInterface $client = null) {}

    public static function isConfigured(): bool
    {
        return filled(config('push.vapid.private_key')) && filled(config('push.vapid.public_key'));
    }

    public function send(PushMessage $message): void
    {
        if (! self::isConfigured()) {
            throw new RuntimeException('Push staat uit: VAPID_PRIVATE_KEY en VAPID_PUBLIC_KEY ontbreken in .env.');
        }

        $subscriptions = PushSubscription::query()->forTopic($message->topic)->get();

        if ($subscriptions->isEmpty()) {
            $message->forceFill(['sent_at' => now()])->save();

            return;
        }

        $webPush = new WebPush(
            auth: [
                'VAPID' => [
                    'subject' => config('push.vapid.subject'),
                    'publicKey' => config('push.vapid.public_key'),
                    'privateKey' => config('push.vapid.private_key'),
                ],
            ],
            defaultOptions: [
                'TTL' => (int) config("push.topics.{$message->topic}.ttl", 86400),
                'urgency' => 'normal',
            ],
            client: $this->client,
            // Zonder logger meldt de bibliotheek zich met trigger_error; met
            // logger komen dezelfde meldingen (bv. "geen bcmath") in de logs.
            logger: Log::getLogger(),
        );
        $webPush->setReuseVAPIDHeaders(true);

        $payload = $message->payload();

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => ['p256dh' => $subscription->p256dh, 'auth' => $subscription->auth],
                    // RFC 8291, wat elke browser met Web Push vandaag spreekt; de
                    // standaard van de bibliotheek is nog het oudere aesgcm.
                    'contentEncoding' => 'aes128gcm',
                ]),
                $payload,
            );
        }

        $byHash = $subscriptions->keyBy('endpoint_hash');
        $sent = $expired = $failed = 0;

        /** @var MessageSentReport $report */
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                $sent++;

                continue;
            }

            if ($report->isSubscriptionExpired() && $this->forget($byHash, $report->getEndpoint())) {
                $expired++;

                continue;
            }

            $failed++;
            Log::warning('Pushbericht niet afgeleverd', [
                'message_id' => $message->id,
                'status' => $report->getResponse()?->getStatusCode(),
                'reason' => $report->getReason(),
            ]);
        }

        $message->forceFill([
            'sent_count' => $sent,
            'expired_count' => $expired,
            'failed_count' => $failed,
            'sent_at' => now(),
        ])->save();
    }

    /**
     * Gooit het abonnement van een dood endpoint weg, en zegt of dat lukte.
     *
     * De sleutel is de hash van het endpoint zoals wij het bewaarden. Meldt de
     * pushdienst een URL terug die daar niet lettergreep voor lettergreep mee
     * overeenkomt, dan zou `?->delete()` stil niets doen en toch als opgeruimd
     * in het logboek komen. Vandaar de tweede poging op de kolom zelf, en een
     * telling bij de mislukkingen als ook die niets raakt.
     *
     * @param  Collection<string, PushSubscription>  $byHash
     */
    private function forget(Collection $byHash, string $endpoint): bool
    {
        if ($subscription = $byHash->get(PushSubscription::hashEndpoint($endpoint))) {
            $subscription->delete();

            return true;
        }

        return PushSubscription::query()->where('endpoint', $endpoint)->delete() > 0;
    }
}
