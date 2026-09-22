<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;
use App\Filament\Pages\Pushberichten;
use App\Filament\Resources\Rounds\Pages\ViewRound;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Models\Round;
use App\Models\Season;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\FakesPushService;
use Tests\TestCase;

class PushberichtenPageTest extends TestCase
{
    use FakesPushService;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakePushService([201, 201, 201, 201]);
    }

    public function test_de_pagina_toont_de_tellers_en_het_logboek(): void
    {
        PushSubscription::factory()->topics(['club', 'intraclub'])->create();
        PushSubscription::factory()->topics(['intraclub'])->create();
        PushMessage::create(['topic' => 'club', 'title' => 'Ledenfeest', 'body' => 'Zaterdag 20u', 'sent_at' => now(), 'sent_count' => 1]);

        $this->actingAs(User::factory()->create());

        $this->get('/admin/pushberichten')
            ->assertSuccessful()
            ->assertSee('2 toestellen geabonneerd')
            ->assertSee('Clubberichten: 1')
            ->assertSee('Intraclub: 2')
            ->assertSee('Ledenfeest')
            ->assertDontSee('Push staat uit');
    }

    public function test_zonder_sleutels_zegt_de_pagina_dat_push_uit_staat(): void
    {
        config(['push.vapid.private_key' => null]);
        $this->actingAs(User::factory()->create());

        $this->get('/admin/pushberichten')
            ->assertSuccessful()
            ->assertSee('Push staat uit');
    }

    public function test_een_bericht_dat_blijft_hangen_wijst_naar_de_cron(): void
    {
        PushMessage::create(['topic' => 'club', 'title' => 'Wacht', 'body' => '…'])
            ->forceFill(['created_at' => now()->subMinutes(10)])
            ->saveQuietly();
        $this->actingAs(User::factory()->create());

        $this->get('/admin/pushberichten')->assertSee('De wachtrij loopt niet leeg');
    }

    public function test_een_zaalaccount_raakt_niet_aan_de_pagina(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]));

        $this->get('/admin/pushberichten')->assertForbidden();
    }

    public function test_een_clubbericht_versturen_gaat_naar_de_clubabonnees(): void
    {
        PushSubscription::factory()->topics(['club'])->create();
        PushSubscription::factory()->topics(['club', 'intraclub'])->create();
        PushSubscription::factory()->topics(['intraclub'])->create();

        $admin = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(Pushberichten::class)
            ->callAction('send', data: [
                'title' => 'Geen badminton op 2 oktober',
                'body' => 'De sporthal is die avond bezet. Volgende week spelen we gewoon.',
                'url' => '',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified();

        $message = PushMessage::sole();
        $this->assertSame('club', $message->topic);
        $this->assertSame('Geen badminton op 2 oktober', $message->title);
        $this->assertSame('https://www.bclandegem.be/', $message->url);
        $this->assertSame($admin->id, $message->user_id);
        $this->assertSame(2, $message->sent_count);
        $this->assertCount(2, $this->pushedEndpoints());
    }

    public function test_titel_en_bericht_zijn_verplicht(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Pushberichten::class)
            ->callAction('send', data: ['title' => '', 'body' => '', 'url' => 'geen url'])
            ->assertHasActionErrors(['title', 'body', 'url']);

        $this->assertDatabaseCount('push_messages', 0);
    }

    public function test_het_intraclub_bericht_van_een_speeldag_opnieuw_versturen(): void
    {
        PushSubscription::factory()->topics(['intraclub'])->create();
        $season = Season::create(['name' => '2026 - 2027', 'points_per_set' => PointsPerSet::Fifteen]);
        $round = Round::create([
            'season_id' => $season->id,
            'number' => 4,
            'date' => '2026-01-15',
            'is_calculated' => true,
            'push_notified_at' => now()->subDay(),
        ]);

        $admin = User::factory()->create();
        $this->actingAs($admin);

        Livewire::test(ViewRound::class, ['record' => $round->getRouteKey()])
            ->callAction('push')
            ->assertHasNoActionErrors()
            ->assertNotified();

        $message = PushMessage::sole();
        $this->assertSame('intraclub', $message->topic);
        $this->assertSame('Intraclub: speeldag 4 berekend', $message->title);
        $this->assertSame('De nieuwe stand na 15 januari staat online.', $message->body);
        $this->assertSame($round->id, $message->round_id);
        $this->assertSame($admin->id, $message->user_id);
        $this->assertSame(1, $message->sent_count);
    }

    public function test_de_herzendknop_bestaat_niet_voor_een_onberekende_speeldag(): void
    {
        $season = Season::create(['name' => '2026 - 2027', 'points_per_set' => PointsPerSet::Fifteen]);
        $round = Round::create(['season_id' => $season->id, 'number' => 1, 'date' => '2026-01-15']);

        $this->actingAs(User::factory()->create());

        Livewire::test(ViewRound::class, ['record' => $round->getRouteKey()])
            ->assertActionHidden('push');
    }
}
