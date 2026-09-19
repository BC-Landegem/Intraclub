<?php

namespace Tests\Feature;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mime\Address;
use Tests\TestCase;

/*
 * Het meldformulier heeft iets wat het contactformulier niet heeft: er is niemand
 * die merkt dat het stuk is. Geen bevestiging naar de inzender, geen logregel met
 * de inhoud, geen tweede ontvanger. Een kapot contactformulier levert binnen de
 * week een telefoontje op; dit kan een jaar verkeerd staan.
 *
 * Elke test hieronder houdt daarom één concrete misgang tegen, en niet "de code
 * doet wat ze doet". Wat het meldformulier deelt met contact — honeypot, tijdslot
 * en de redirect-allowlist zelf — staat in ContactFormTest en wordt hier niet
 * overgedaan; enkel dat de gedeelde trait correct aangesloten is.
 *
 * Mail::fake() kan hier niet: MailFake::raw() is een lege methode en slikt de
 * boodschap. Vandaar de array-transport uit phpunit.xml.
 */
class MeldingFormTest extends TestCase
{
    private const AANSPREEKPUNT = 'aanspreekpunt@voorbeeld.be';

    private const BESTUUR = 'info@bclandegem.be';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'contact.return_origins' => ['https://bclandegem.be', 'http://localhost:4321'],
            'contact.turnstile_secret' => null,
            'melding.to' => self::AANSPREEKPUNT,
            'mail.from.address' => self::BESTUUR,
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'message' => 'Een trainer maakte herhaaldelijk opmerkingen over het lichaam van mijn dochter.',
            'website' => '',
            'loaded_at' => (string) (now()->getTimestampMs() - 10000),
            'return_ok' => 'https://bclandegem.be/club/melden/bedankt/',
            'return_error' => 'https://bclandegem.be/club/melden/',
        ], $overrides);
    }

    /** De berichten die de array-transport uit phpunit.xml heeft opgevangen. */
    private function verzonden(): Collection
    {
        return Mail::mailer()->getSymfonyTransport()->messages();
    }

    /*
     * 1. De terugval die er niet mag zijn. config/contact.php doet
     *    env('CONTACT_TO', 'info@bclandegem.be'); wie dat patroon hier kopieert,
     *    bouwt een formulier dat elke melding stil naar het bestuur stuurt.
     */
    public function test_zonder_geconfigureerde_ontvanger_vertrekt_er_niets(): void
    {
        Log::spy();
        config(['melding.to' => null]);

        $this->post('/api/melding', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/melden/?error=unavailable');

        $this->assertCount(0, $this->verzonden());
    }

    public function test_een_ontvanger_van_enkel_spaties_telt_als_ontbrekend(): void
    {
        // Anders verbruikt de melder een poging en eindigt op ?error=mail.
        Log::spy();
        config(['melding.to' => ' ']);

        $this->post('/api/melding', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/melden/?error=unavailable');

        $this->assertCount(0, $this->verzonden());
    }

    /*
     * 2. De ene test die de hele bedoeling van dit ontwerp vastlegt.
     */
    public function test_de_melding_gaat_naar_het_aanspreekpunt_en_nergens_anders(): void
    {
        $this->post('/api/melding', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/melden/bedankt/');

        $mail = $this->verzonden()->first()->getOriginalMessage();

        $this->assertSame([self::AANSPREEKPUNT], $this->adressen($mail->getTo()));
        $this->assertSame([], $this->adressen($mail->getCc()));
        $this->assertSame([], $this->adressen($mail->getBcc()));
    }

    /*
     * 3. De naam overleeft een Beantwoorden naar het bestuur als hij in het
     *    onderwerp staat; in de body kan het aanspreekpunt hem wissen.
     */
    public function test_het_onderwerp_draagt_het_tijdstip_en_niet_de_naam(): void
    {
        $this->post('/api/melding', $this->payload(['name' => 'Jan Peeters']));

        $onderwerp = $this->verzonden()->first()->getOriginalMessage()->getSubject();

        $this->assertMatchesRegularExpression('/^\[Melding] \d{2}-\d{2}-\d{4} \d{2}:\d{2}$/', $onderwerp);
        $this->assertStringNotContainsString('Jan', $onderwerp);
    }

    /*
     * 4. De vergissing die je nooit bewust maakt, maar wel bij het kopiëren: één
     *    emmer voor beide formulieren. Drie contactberichten van een gezin achter
     *    dezelfde NAT kosten dan een melding.
     */
    public function test_het_contactformulier_verbruikt_het_quotum_van_de_melding_niet(): void
    {
        config(['contact.to' => self::BESTUUR]);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/api/contact', [
                'name' => 'Jan Peeters',
                'email' => 'jan@voorbeeld.be',
                'message' => 'Kan ik eens komen proberen?',
                'loaded_at' => (string) (now()->getTimestampMs() - 10000),
                'return_ok' => 'https://bclandegem.be/club/contact/bedankt/',
                'return_error' => 'https://bclandegem.be/club/contact/',
            ])->assertRedirect('https://bclandegem.be/club/contact/bedankt/');
        }

        $this->post('/api/melding', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/melden/bedankt/');
    }

    public function test_de_vierde_melding_binnen_het_uur_wordt_geweigerd(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post('/api/melding', $this->payload())
                ->assertRedirect('https://bclandegem.be/club/melden/bedankt/');
        }

        $this->post('/api/melding', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/melden/?error=throttle');

        $this->assertCount(3, $this->verzonden());
    }

    /*
     * 5. Bewust anders dan contact, dus zonder test harmoniseert iemand het weg.
     *    Een spammer kan onze verbinding met Cloudflare niet platleggen, dus dit
     *    venster kan hij niet zelf openen.
     */
    public function test_een_onbereikbare_turnstile_laat_door_met_een_merk_in_het_onderwerp(): void
    {
        Log::spy();
        config(['contact.turnstile_secret' => 'geheim']);
        Http::fake(fn () => throw new ConnectionException('time-out'));

        $this->post('/api/melding', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/melden/bedankt/');

        $this->assertStringStartsWith(
            '[Melding][ongeverifieerd] ',
            $this->verzonden()->first()->getOriginalMessage()->getSubject()
        );
    }

    public function test_een_afgekeurde_turnstile_gaat_wel_dicht(): void
    {
        config(['contact.turnstile_secret' => 'geheim']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

        $this->post('/api/melding', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/melden/?error=captcha');

        $this->assertCount(0, $this->verzonden());
    }

    public function test_een_storing_bij_cloudflare_laat_door_met_een_merk(): void
    {
        Log::spy();
        config(['contact.turnstile_secret' => 'geheim']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response([], 500)]);

        $this->post('/api/melding', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/melden/bedankt/');

        $this->assertStringStartsWith(
            '[Melding][ongeverifieerd] ',
            $this->verzonden()->first()->getOriginalMessage()->getSubject()
        );
    }

    public function test_een_onleesbaar_antwoord_van_cloudflare_laat_door_met_een_merk(): void
    {
        // Een 200 met een HTML-pagina van een proxy is geen afkeuring.
        Log::spy();
        config(['contact.turnstile_secret' => 'geheim']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response('<html>storing</html>', 200)]);

        $this->post('/api/melding', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/melden/bedankt/');

        $this->assertStringStartsWith(
            '[Melding][ongeverifieerd] ',
            $this->verzonden()->first()->getOriginalMessage()->getSubject()
        );
    }

    public function test_een_4xx_van_cloudflare_gaat_dicht(): void
    {
        // Een te groot token of te veel requests kan een inzender zelf uitlokken;
        // dat mag het venster van "geen oordeel" niet openen.
        config(['contact.turnstile_secret' => 'geheim']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response('<html>413</html>', 413)]);

        $this->post('/api/melding', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/melden/?error=captcha');

        $this->assertCount(0, $this->verzonden());
    }

    /*
     * 6. Eén sleutelpaar voor beide formulieren: de action is het enige dat een
     *    token van het contactformulier hier tegenhoudt.
     */
    public function test_een_token_met_de_action_van_het_contactformulier_wordt_geweigerd(): void
    {
        config(['contact.turnstile_secret' => 'geheim']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true, 'action' => 'contact'])]);

        $this->post('/api/melding', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/melden/?error=captcha');

        $this->assertCount(0, $this->verzonden());
    }

    /*
     * 7. Anoniem melden moet kunnen, en het contactveld is vrije tekst.
     */
    public function test_een_anonieme_melding_vertrekt_zonder_reply_to(): void
    {
        $this->post('/api/melding', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/melden/bedankt/');

        $mail = $this->verzonden()->first()->getOriginalMessage();

        $this->assertSame([], $this->adressen($mail->getReplyTo()));
        $this->assertStringContainsString('(niet ingevuld)', $mail->getTextBody());
    }

    public function test_een_telefoonnummer_in_het_contactveld_wordt_geen_reply_to(): void
    {
        $this->post('/api/melding', $this->payload(['contact' => '0475 12 34 56, liefst na 18u']));

        $mail = $this->verzonden()->first()->getOriginalMessage();

        $this->assertSame([], $this->adressen($mail->getReplyTo()));
        $this->assertStringContainsString('0475 12 34 56', $mail->getTextBody());
    }

    public function test_een_e_mailadres_in_het_contactveld_wordt_wel_reply_to(): void
    {
        $this->post('/api/melding', $this->payload(['contact' => 'melder@voorbeeld.be']));

        $this->assertSame(
            ['melder@voorbeeld.be'],
            $this->adressen($this->verzonden()->first()->getOriginalMessage()->getReplyTo())
        );
    }

    /*
     * 8. Bij een anonieme melding breekt een IP-adres of een user-agent in de mail
     *    precies datgene waarvoor het formulier bestaat. En de inhoud hoort in
     *    geen enkele logregel.
     */
    public function test_de_mail_draagt_geen_ip_geen_user_agent_en_de_inhoud_komt_niet_in_het_log(): void
    {
        Log::spy();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/api/melding', $this->payload(), ['User-Agent' => 'Mozilla/5.0 (VerdachteBrowser)']);

        $mail = $this->verzonden()->first()->getOriginalMessage();
        $tekst = $mail->getTextBody().' '.$mail->getSubject();

        $this->assertStringNotContainsString('203.0.113.9', $tekst);
        $this->assertStringNotContainsString('VerdachteBrowser', $tekst);

        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('error');
    }

    public function test_de_waarschuwing_over_beantwoorden_staat_bovenaan_de_mail(): void
    {
        // From blijft de bestuursmailbox; dat risico is aanvaard, maar het
        // aanspreekpunt moet het zien vóór ze het bericht leest.
        $this->post('/api/melding', $this->payload());

        $tekst = $this->verzonden()->first()->getOriginalMessage()->getTextBody();

        $this->assertStringStartsWith('LET OP:', $tekst);
        $this->assertStringContainsString(self::BESTUUR, $tekst);
    }

    /*
     * Het contactveld gaat mogelijk de reply-to-header in; een regeleinde erin is
     * header-injectie.
     */
    public function test_een_regeleinde_in_het_contactveld_wordt_geweigerd(): void
    {
        $this->post('/api/melding', $this->payload(['contact' => 'melder@voorbeeld.be
Bcc: spam@voorbeeld.be']))
            ->assertRedirect('https://bclandegem.be/club/melden/?error=validation');

        $this->assertCount(0, $this->verzonden());
    }

    public function test_zonder_bericht_volgt_error_validation(): void
    {
        $this->post('/api/melding', $this->payload(['message' => '']))
            ->assertRedirect('https://bclandegem.be/club/melden/?error=validation');

        $this->assertCount(0, $this->verzonden());
    }

    public function test_een_return_adres_als_array_geeft_geen_500(): void
    {
        $this->post('/api/melding', [
            ...$this->payload(['loaded_at' => (string) now()->getTimestampMs()]),
            'return_error' => ['https://bclandegem.be/club/melden/'],
        ])->assertRedirect('https://bclandegem.be/club/melden/?error=bot');
    }

    /*
     * 9. Bewijst enkel dat de gedeelde trait correct aangesloten is; de
     *    allowlist zelf wordt in ContactFormTest uitgeplozen.
     */
    public function test_een_vreemd_return_adres_valt_terug_op_de_meldpagina(): void
    {
        $this->post('/api/melding', $this->payload([
            'return_error' => 'https://kwaadaardig.example/phishing',
            'loaded_at' => (string) now()->getTimestampMs(),
        ]))->assertRedirect('https://bclandegem.be/club/melden/?error=bot');
    }

    public function test_een_mislukte_verzending_stuurt_terug_met_error_mail(): void
    {
        Log::spy();
        Mail::shouldReceive('raw')->once()->andThrow(new RuntimeException('smtp weg'));

        $this->post('/api/melding', $this->payload())
            ->assertRedirect('https://bclandegem.be/club/melden/?error=mail');
    }

    /**
     * @param  iterable<Address>  $adressen
     * @return list<string>
     */
    private function adressen(iterable $adressen): array
    {
        $uit = [];

        foreach ($adressen as $adres) {
            $uit[] = $adres->getAddress();
        }

        return $uit;
    }
}
