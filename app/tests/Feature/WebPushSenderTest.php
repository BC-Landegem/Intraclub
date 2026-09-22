<?php

namespace Tests\Feature;

use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Services\Push\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesPushService;
use Tests\TestCase;

/*
 * Het versturen zelf, tegen een nagespeelde pushdienst. Waar het om gaat: een
 * 410 (of 404) is geen fout maar het einde van een abonnement, en het logboek
 * moet de drie uitkomsten uit elkaar houden.
 */
class WebPushSenderTest extends TestCase
{
    use FakesPushService;
    use RefreshDatabase;

    public function test_verstuurt_naar_de_abonnees_van_het_onderwerp_en_ruimt_dode_endpoints_op(): void
    {
        $alive = PushSubscription::factory()->topics(['club'])->create();
        $gone = PushSubscription::factory()->topics(['club', 'intraclub'])->create();
        $broken = PushSubscription::factory()->topics(['club'])->create();
        $otherTopic = PushSubscription::factory()->topics(['intraclub'])->create();

        // In volgorde van id: 201 voor de eerste, 410 voor de tweede, 500 voor de derde.
        $this->fakePushService([201, 410, 500]);

        $message = PushMessage::create(['topic' => 'club', 'title' => 'Test', 'body' => 'Inhoud', 'url' => 'https://www.bclandegem.be/']);

        app(WebPushSender::class)->send($message);

        $this->assertEqualsCanonicalizing(
            [$alive->endpoint, $gone->endpoint, $broken->endpoint],
            $this->pushedEndpoints(),
        );

        $message->refresh();
        $this->assertSame(1, $message->sent_count);
        $this->assertSame(1, $message->expired_count);
        $this->assertSame(1, $message->failed_count);
        $this->assertNotNull($message->sent_at);

        $this->assertModelMissing($gone);
        $this->assertModelExists($alive);
        $this->assertModelExists($broken);
        $this->assertModelExists($otherTopic);
    }

    public function test_de_payload_is_wat_de_service_worker_verwacht(): void
    {
        PushSubscription::factory()->topics(['intraclub'])->create();
        $this->fakePushService([201]);

        $message = PushMessage::create([
            'topic' => 'intraclub',
            'title' => 'Intraclub: speeldag 3 berekend',
            'body' => 'De nieuwe stand staat online.',
            'url' => 'https://www.bclandegem.be/intraclub/speeldag/?id=3',
        ]);

        $this->assertSame(
            '{"title":"Intraclub: speeldag 3 berekend","body":"De nieuwe stand staat online.","url":"https://www.bclandegem.be/intraclub/speeldag/?id=3","topic":"intraclub","tag":"intraclub"}',
            $message->payload(),
        );

        app(WebPushSender::class)->send($message);

        $request = $this->pushRequests[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame((string) (2 * 86400), $request->getHeaderLine('TTL'));
        $this->assertSame('normal', $request->getHeaderLine('Urgency'));
        $this->assertStringStartsWith('vapid t=', $request->getHeaderLine('Authorization'));
        // Versleuteld: de titel mag niet leesbaar over de lijn gaan.
        $this->assertStringNotContainsString('speeldag', (string) $request->getBody());
    }

    /*
     * Een clubbericht heeft geen vaste tag in de config, maar wel een eigen per
     * bericht: mislukt een job halverwege, dan stuurt de retry naar iedereen
     * opnieuw en mag dat geen tweede melding naast de eerste zetten.
     */
    public function test_een_clubbericht_krijgt_een_tag_per_bericht(): void
    {
        $this->configurePush();

        $first = PushMessage::create(['topic' => 'club', 'title' => 'Een', 'body' => 'Inhoud']);
        $second = PushMessage::create(['topic' => 'club', 'title' => 'Twee', 'body' => 'Inhoud']);

        $this->assertSame("club-{$first->id}", json_decode($first->payload(), true)['tag']);
        $this->assertSame("club-{$second->id}", json_decode($second->payload(), true)['tag']);
    }

    public function test_zonder_abonnees_is_het_bericht_meteen_afgehandeld(): void
    {
        $this->fakePushService([]);

        $message = PushMessage::create(['topic' => 'club', 'title' => 'Test', 'body' => 'Inhoud']);
        app(WebPushSender::class)->send($message);

        $this->assertSame([], $this->pushedEndpoints());
        $this->assertNotNull($message->fresh()->sent_at);
    }

    public function test_zonder_sleutels_weigert_de_sender(): void
    {
        PushSubscription::factory()->create();
        config(['push.vapid.private_key' => null, 'push.vapid.public_key' => null]);

        $this->expectExceptionMessage('Push staat uit');

        app(WebPushSender::class)->send(PushMessage::create(['topic' => 'club', 'title' => 'Test', 'body' => 'Inhoud']));
    }
}
