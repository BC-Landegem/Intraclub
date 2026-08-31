<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/*
 * Het contactformulier antwoordt nooit met een foutstatus: de bezoeker komt van
 * een statische site op een ander domein en zou een 422 of 429 als rauwe JSON in
 * zijn adresbalk zien. Elk pad hieronder controleert dus twee dingen: waar de
 * bezoeker landt, en of er mail vertrekt.
 *
 * Mail::fake() kan hier niet: MailFake::raw() is een lege methode en slikt de
 * boodschap. Vandaar de array-transport uit phpunit.xml.
 */
class ContactFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'contact.return_origins' => ['https://bclandegem.be', 'http://localhost:4321'],
            'contact.to' => 'info@bclandegem.be',
            'contact.turnstile_secret' => null,
            'mail.from.address' => 'info@bclandegem.be',
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Jan Peeters',
            'email' => 'jan@voorbeeld.be',
            'message' => 'Kan ik eens komen proberen op donderdag?',
            'website' => '',
            'loaded_at' => (string) (now()->getTimestampMs() - 10000),
            'return_ok' => 'https://bclandegem.be/club/contact/bedankt/',
            'return_error' => 'https://bclandegem.be/club/contact/',
        ], $overrides);
    }

    /** De berichten die de array-transport uit phpunit.xml heeft opgevangen. */
    private function verzonden(): Collection
    {
        return Mail::mailer()->getSymfonyTransport()->messages();
    }

    public function test_een_geldige_inzending_verstuurt_mail_en_landt_op_de_bedankpagina(): void
    {
        $this->post('/api/contact', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/contact/bedankt/');

        $this->assertCount(1, $this->verzonden());
    }

    public function test_de_mail_komt_van_de_club_en_antwoordt_naar_de_bezoeker(): void
    {
        $this->post('/api/contact', $this->payload());

        $mail = $this->verzonden()->first()->getOriginalMessage();

        // De bezoeker als afzender zetten is mail versturen namens zijn provider,
        // en dan faalt SPF. Hij hoort enkel in reply-to.
        $this->assertSame('info@bclandegem.be', $mail->getFrom()[0]->getAddress());
        $this->assertSame('info@bclandegem.be', $mail->getTo()[0]->getAddress());
        $this->assertSame('jan@voorbeeld.be', $mail->getReplyTo()[0]->getAddress());
        $this->assertSame('[Website] Bericht van Jan Peeters', $mail->getSubject());
        $this->assertStringContainsString('donderdag', $mail->getTextBody());
    }

    public function test_de_honeypot_doet_alsof_het_gelukt_is_maar_verstuurt_niets(): void
    {
        // Een bot die een foutmelding krijgt, leert bij en probeert opnieuw.
        $this->post('/api/contact', $this->payload(['website' => 'https://spam.example']))
            ->assertRedirect('https://bclandegem.be/club/contact/bedankt/');

        $this->assertCount(0, $this->verzonden());
    }

    public function test_binnen_de_seconde_posten_wordt_geweigerd(): void
    {
        $this->post('/api/contact', $this->payload(['loaded_at' => (string) now()->getTimestampMs()]))
            ->assertRedirect('https://bclandegem.be/club/contact/?error=bot');

        $this->assertCount(0, $this->verzonden());
    }

    public function test_zonder_tijdstempel_wordt_geweigerd(): void
    {
        $this->post('/api/contact', $this->payload(['loaded_at' => '']))
            ->assertRedirect('https://bclandegem.be/club/contact/?error=bot');
    }

    public function test_een_ongeldig_veld_geeft_een_redirect_en_geen_json_422(): void
    {
        $this->post('/api/contact', $this->payload(['email' => 'geen adres']))
            ->assertRedirect('https://bclandegem.be/club/contact/?error=validation');

        $this->assertCount(0, $this->verzonden());
    }

    public function test_een_naam_met_een_regeleinde_komt_de_onderwerpregel_niet_in(): void
    {
        // Header-injectie: de naam gaat mee in het onderwerp.
        $this->post('/api/contact', $this->payload([
            'name' => "Jan\r\nBcc: iedereen@voorbeeld.be",
        ]))->assertRedirect('https://bclandegem.be/club/contact/?error=validation');

        $this->assertCount(0, $this->verzonden());
    }

    public function test_de_vierde_inzending_binnen_het_uur_stuit_op_de_begrenzing(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post('/api/contact', $this->payload())
                ->assertRedirect('https://bclandegem.be/club/contact/bedankt/');
        }

        $this->post('/api/contact', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/contact/?error=throttle');

        $this->assertCount(3, $this->verzonden());
    }

    public function test_een_mislukte_verzending_stuurt_terug_met_error_mail(): void
    {
        Log::spy();
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('smtp weg'));

        $this->post('/api/contact', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/contact/?error=mail');
    }

    public function test_een_vreemd_return_adres_valt_terug_op_de_eerste_toegelaten_origin(): void
    {
        // Zonder deze controle is return_ok een open redirect.
        $this->post('/api/contact', $this->payload([
            'return_ok' => 'https://kwaadaardig.example/phishing',
        ]))->assertRedirect('https://bclandegem.be/club/contact/');
    }

    public function test_een_ander_toegelaten_origin_mag_wel(): void
    {
        $this->post('/api/contact', $this->payload([
            'return_ok' => 'http://localhost:4321/Website/club/contact/bedankt/',
        ]))->assertRedirect('http://localhost:4321/Website/club/contact/bedankt/');
    }

    public function test_querystring_en_fragment_van_de_bezoeker_gaan_er_af(): void
    {
        $this->post('/api/contact', $this->payload([
            'loaded_at' => (string) now()->getTimestampMs(),
            'return_error' => 'https://bclandegem.be/club/contact/?error=al-iets#fragment',
        ]))->assertRedirect('https://bclandegem.be/club/contact/?error=bot');
    }

    public function test_zonder_geconfigureerde_origins_landt_de_bezoeker_op_de_eigen_app(): void
    {
        config(['contact.return_origins' => []]);

        $this->post('/api/contact', $this->payload())
            ->assertRedirect(rtrim((string) config('app.url'), '/').'/club/contact/');
    }

    public function test_turnstile_keurt_af_en_er_vertrekt_geen_mail(): void
    {
        config(['contact.turnstile_secret' => 'geheim']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this->post('/api/contact', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/contact/?error=captcha');

        $this->assertCount(0, $this->verzonden());
    }

    public function test_turnstile_keurt_goed_en_de_mail_vertrekt(): void
    {
        config(['contact.turnstile_secret' => 'geheim']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->post('/api/contact', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/contact/bedankt/');

        $this->assertCount(1, $this->verzonden());
    }

    public function test_een_onbereikbare_turnstile_gaat_dicht_en_niet_open(): void
    {
        Log::spy();
        config(['contact.turnstile_secret' => 'geheim']);
        Http::fake(fn () => throw new ConnectionException('time-out'));

        $this->post('/api/contact', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/contact/?error=captcha');

        $this->assertCount(0, $this->verzonden());
    }

    public function test_zonder_secret_wordt_turnstile_overgeslagen(): void
    {
        Http::fake();

        $this->post('/api/contact', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/contact/bedankt/');

        Http::assertNothingSent();
    }
}
