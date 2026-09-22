<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/*
 * Abonnementen op pushberichten, aangemeld door de clubwebsite. Het contract
 * staat in de README van de Website-repo onder "Databronnen · Pushberichten";
 * de kern:
 *
 *   PUT    body = PushSubscription.toJSON() van de browser (endpoint, keys)
 *          + optioneel topics[] en previous_endpoint. Upsert op endpoint.
 *          Zonder topics blijven de bewaarde onderwerpen staan ([] voor een
 *          nieuw endpoint): zo leest de site de stand terug zonder eigen GET.
 *          Met previous_endpoint (uit pushsubscriptionchange in de service
 *          worker) neemt de nieuwe rij de onderwerpen van de oude over en gaat
 *          de oude eruit. Antwoord 200 { topics }.
 *   DELETE body = { endpoint } → 204.
 *
 * Anders dan de formulieren is dit een fetch met JSON, dus mogen 422 en 429
 * hier gewoon als JSON terug: de site vertaalt ze zelf naar een zin.
 *
 * Dit endpoint staat open zonder login — een abonnement is anoniem, dat is de
 * afspraak met de bezoeker. De remmen: throttle per IP (routes/api.php) en de
 * lijst van pushdiensten in config/push.php, want het endpoint is een URL waar
 * deze server straks naartoe POST.
 */
class PushSubscriptionController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000', 'url:https', $this->knownPushService()],
            'keys' => ['required', 'array'],
            // 65 bytes P-256-punt en 16 bytes auth-secret, base64url zonder padding.
            'keys.p256dh' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{86,88}$/'],
            'keys.auth' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{22,24}$/'],
            'topics' => ['sometimes', 'array', 'max:10'],
            'topics.*' => ['string', Rule::in(array_keys(config('push.topics')))],
            'previous_endpoint' => ['nullable', 'string', 'max:2000', 'url:https'],
        ], [
            'topics.*.in' => 'Onbekend onderwerp.',
        ]);

        $topics = DB::transaction(function () use ($data): array {
            $subscription = PushSubscription::findByEndpoint($data['endpoint']);
            $inherited = null;

            $previous = $data['previous_endpoint'] ?? null;
            if ($previous !== null && $previous !== $data['endpoint']) {
                $old = PushSubscription::findByEndpoint($previous);
                if ($old !== null) {
                    $inherited = $old->topics;
                    $old->delete();
                }
            }

            $topics = array_values(array_unique(
                $data['topics'] ?? $subscription?->topics ?? $inherited ?? []
            ));

            PushSubscription::updateOrCreate(
                ['endpoint_hash' => PushSubscription::hashEndpoint($data['endpoint'])],
                [
                    'endpoint' => $data['endpoint'],
                    'p256dh' => $data['keys']['p256dh'],
                    'auth' => $data['keys']['auth'],
                    'topics' => $topics,
                ],
            );

            return $topics;
        });

        return response()->json(['topics' => $topics]);
    }

    public function destroy(Request $request): Response
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:2000'],
        ]);

        PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::hashEndpoint($data['endpoint']))
            ->delete();

        return response()->noContent();
    }

    /** De host van het endpoint moet op één van de bekende pushdiensten eindigen. */
    private function knownPushService(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $host = is_string($value) ? strtolower((string) parse_url($value, PHP_URL_HOST)) : '';

            foreach (config('push.endpoint_hosts') as $allowed) {
                if ($host === $allowed || Str::endsWith($host, '.'.$allowed)) {
                    return;
                }
            }

            $fail('Deze pushdienst kennen we niet.');
        };
    }
}
