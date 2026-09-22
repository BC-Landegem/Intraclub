<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\FakesPushService;
use Tests\TestCase;

/*
 * Het contract met de site (README van de Website-repo, "Databronnen ·
 * Pushberichten"). Het lastige zit in wat een PUT zónder topics doet: dat is
 * hoe de instellingenpagina de bewaarde stand terugleest, dus die mag nooit
 * onderwerpen wissen.
 */
class PushSubscriptionApiTest extends TestCase
{
    use FakesPushService;
    use RefreshDatabase;

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/abc123';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('push');
    }

    public function test_een_nieuw_abonnement_met_onderwerpen_wordt_bewaard(): void
    {
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload(self::ENDPOINT, ['topics' => ['club', 'intraclub']]))
            ->assertOk()
            ->assertExactJson(['topics' => ['club', 'intraclub']]);

        $subscription = PushSubscription::findByEndpoint(self::ENDPOINT);
        $this->assertNotNull($subscription);
        $this->assertSame(['club', 'intraclub'], $subscription->topics);
        $this->assertSame('tBHItJI5svbpez7KI4CCXg', $subscription->auth);
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_een_put_zonder_onderwerpen_leest_de_bewaarde_stand_terug(): void
    {
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload(self::ENDPOINT, ['topics' => ['intraclub']]))->assertOk();

        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload(self::ENDPOINT))
            ->assertOk()
            ->assertExactJson(['topics' => ['intraclub']]);

        $this->assertSame(['intraclub'], PushSubscription::findByEndpoint(self::ENDPOINT)->topics);
    }

    public function test_een_onbekend_endpoint_zonder_onderwerpen_geeft_een_lege_lijst(): void
    {
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload(self::ENDPOINT))
            ->assertOk()
            ->assertExactJson(['topics' => []]);

        // En laat niets achter: een rij zonder onderwerpen krijgt nooit een
        // bericht, dus ook nooit de 404/410 waarmee ze opgeruimd zou worden.
        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_alles_uitvinken_verwijdert_het_abonnement(): void
    {
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload(self::ENDPOINT, ['topics' => ['club']]))->assertOk();

        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload(self::ENDPOINT, ['topics' => []]))
            ->assertOk()
            ->assertExactJson(['topics' => []]);

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_onderwerpen_overschrijven_de_vorige_keuze(): void
    {
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload(self::ENDPOINT, ['topics' => ['club', 'intraclub']]))->assertOk();

        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload(self::ENDPOINT, ['topics' => ['club']]))
            ->assertOk()
            ->assertExactJson(['topics' => ['club']]);
    }

    public function test_een_onbekend_onderwerp_geeft_422(): void
    {
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload(self::ENDPOINT, ['topics' => ['club', 'kalender']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['topics.1']);

        $this->assertDatabaseCount('push_subscriptions', 0);
    }

    public function test_een_vernieuwd_abonnement_neemt_de_onderwerpen_van_het_oude_over(): void
    {
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload(self::ENDPOINT, ['topics' => ['intraclub']]))->assertOk();

        $new = 'https://fcm.googleapis.com/fcm/send/nieuw456';
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload($new, ['previous_endpoint' => self::ENDPOINT]))
            ->assertOk()
            ->assertExactJson(['topics' => ['intraclub']]);

        $this->assertNull(PushSubscription::findByEndpoint(self::ENDPOINT));
        $this->assertSame(['intraclub'], PushSubscription::findByEndpoint($new)->topics);
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_een_endpoint_bij_een_onbekende_pushdienst_wordt_geweigerd(): void
    {
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload('https://evil.example.com/push/abc', ['topics' => ['club']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint']);

        // Wel een subdomein van een bekende dienst (Firefox, oudere Edge).
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload('https://updates.push.services.mozilla.com/wpush/v2/abc', ['topics' => ['club']]))
            ->assertOk();
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload('https://wns2-par02p.notify.windows.com/w/?token=abc', ['topics' => ['club']]))
            ->assertOk();

        // Geen http, geen verzonnen voorvoegsel.
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload('http://fcm.googleapis.com/fcm/send/abc', ['topics' => ['club']]))
            ->assertUnprocessable();
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload('https://fcm.googleapis.com.evil.example/fcm/send/abc', ['topics' => ['club']]))
            ->assertUnprocessable();
    }

    public function test_sleutels_moeten_de_juiste_vorm_hebben(): void
    {
        $payload = $this->subscriptionPayload(self::ENDPOINT, ['topics' => ['club']]);
        $payload['keys']['auth'] = 'kort';

        $this->putJson('/api/push/subscriptions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys.auth']);

        $this->putJson('/api/push/subscriptions', ['endpoint' => self::ENDPOINT, 'topics' => ['club']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['keys']);
    }

    public function test_afmelden_verwijdert_het_abonnement(): void
    {
        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload(self::ENDPOINT, ['topics' => ['club']]))->assertOk();

        $this->deleteJson('/api/push/subscriptions', ['endpoint' => self::ENDPOINT])->assertNoContent();
        $this->assertDatabaseCount('push_subscriptions', 0);

        // Nog eens afmelden is geen fout: de browser kan het abonnement al kwijt zijn.
        $this->deleteJson('/api/push/subscriptions', ['endpoint' => self::ENDPOINT])->assertNoContent();
    }

    public function test_te_veel_verzoeken_van_een_ip_geven_429(): void
    {
        config(['push.max_per_minute' => 3]);

        foreach (range(1, 3) as $i) {
            $this->putJson('/api/push/subscriptions', $this->subscriptionPayload(self::ENDPOINT))->assertOk();
        }

        $this->putJson('/api/push/subscriptions', $this->subscriptionPayload(self::ENDPOINT))->assertTooManyRequests();
    }

    public function test_het_endpoint_laat_de_site_binnen_via_cors(): void
    {
        $this->withHeaders([
            'Origin' => 'https://www.bclandegem.be',
            'Access-Control-Request-Method' => 'PUT',
        ])->options('/api/push/subscriptions')
            ->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', 'https://www.bclandegem.be');
    }
}
