<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

/*
 * Contactformulier van de clubwebsite. De site is statisch en staat op een ander
 * domein, dus dit is een gewone cross-origin form-POST: geen fetch, geen JSON,
 * geen CSRF-token. Het antwoord is altijd een redirect terug naar de pagina waar
 * de bezoeker vandaan kwam — die stuurt zelf mee waar ze staat, zodat dit blijft
 * werken op localhost, op github.io en na de domeinswitch.
 *
 * Daarom geeft dit endpoint nooit een foutstatus terug. Een 419, 422, 429 of 500
 * belandt hier als rauwe JSON in de adresbalk van een vreemd domein (deze app
 * rendert alles onder /api/ als JSON, zie bootstrap/app.php). Elke uitkomst wordt
 * dus een 302 met een foutcode in de querystring:
 *
 *   ?error=bot         te snel ingevuld
 *   ?error=captcha     Turnstile keurde de inzending af of was onbereikbaar
 *   ?error=validation  naam, e-mail of bericht ontbreekt of deugt niet
 *   ?error=throttle    te veel inzendingen vanaf hetzelfde IP
 *   ?error=mail        het versturen zelf mislukte
 *
 * De honeypot is de uitzondering: die doet alsof het gelukt is. Een bot die een
 * foutmelding krijgt, leert bij en probeert opnieuw.
 */
class ContactController extends Controller
{
    /*
     * Waar de bezoeker landt als het formulier geen bruikbaar return-adres
     * meestuurt: de contactpagina op de eerste toegelaten origin.
     */
    private const FALLBACK_PATH = '/club/contact/';

    public function __invoke(Request $request): RedirectResponse
    {
        $back = fn (string $field, ?string $error = null): RedirectResponse => redirect()->away(
            $this->safeReturn($request->input($field), $error)
        );

        /*
         * 1. Snelheidsbegrenzing. Bewust hier en niet als throttle-middleware: die
         *    antwoordt met een JSON-429, en daar staat de bezoeker dan naar te
         *    kijken. Elke poging telt mee, ook een die hieronder sneuvelt, anders
         *    is een bot die de honeypot invult ongelimiteerd welkom.
         */
        $key = 'contact:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, config('contact.max_per_hour'))) {
            return $back('return_error', 'throttle');
        }

        RateLimiter::hit($key, 3600);

        // 2. Honeypot — stil doen alsof het gelukt is.
        if (filled($request->input('website'))) {
            return $back('return_ok');
        }

        /*
         * 3. Tijdslot. Niet ondertekend en dus vervalsbaar (de site is statisch,
         *    een handtekening zou een extra request kosten) — het vangt alleen de
         *    domme bots die binnen de seconde posten.
         */
        $loaded = (int) $request->input('loaded_at');

        if ($loaded <= 0 || (now()->getTimestampMs() - $loaded) < config('contact.min_seconds') * 1000) {
            return $back('return_error', 'bot');
        }

        // 4. Turnstile, als er een secret geconfigureerd is.
        if ($secret = config('contact.turnstile_secret')) {
            try {
                $ok = Http::asForm()
                    ->timeout(5)
                    ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                        'secret' => $secret,
                        'response' => $request->input('cf-turnstile-response'),
                        'remoteip' => $request->ip(),
                    ])
                    ->json('success');
            } catch (Throwable $e) {
                // Cloudflare onbereikbaar: dicht, niet open. De bezoeker kan opnieuw.
                report($e);
                $ok = false;
            }

            if ($ok !== true) {
                return $back('return_error', 'captcha');
            }
        }

        /*
         * 5. Pas nu valideren. Andersom verbruikt elke bot een Turnstile-call en
         *    vult hij de logs met validatiefouten.
         */
        try {
            $data = $request->validate([
                // Geen \r of \n in de naam: die gaat de onderwerpregel in, en dat is
                // waar header-injectie binnenkomt.
                'name' => ['required', 'string', 'max:100', 'regex:/^[^\r\n]+$/'],
                'email' => ['required', 'email:rfc', 'max:190'],
                'message' => ['required', 'string', 'max:5000'],
            ]);
        } catch (ValidationException $e) {
            return $back('return_error', 'validation');
        }

        try {
            /*
             * Platte tekst, geen HTML-template: die kan geen HTML-injectie dragen en
             * leest in een mailbox even goed. De afzender blijft MAIL_FROM_ADDRESS —
             * de bezoeker als `from` zetten is mail versturen namens gmail.com, en
             * dan faalt SPF. Antwoorden gaat via reply-to.
             */
            Mail::raw($data['message'], function ($mail) use ($data): void {
                $mail->to(config('contact.to'))
                    ->replyTo($data['email'], $data['name'])
                    ->subject('[Website] Bericht van '.$data['name']);
            });
        } catch (Throwable $e) {
            report($e);

            return $back('return_error', 'mail');
        }

        return $back('return_ok');
    }

    /*
     * Zonder deze controle is return_ok een open redirect, en staat er een
     * phishing-doorverwijzing op het clubdomein. Enkel scheme+host(+poort) uit
     * config('contact.return_origins') mag; het pad komt van de bezoeker, de rest
     * niet — dus geen querystring en geen fragment van buiten.
     */
    private function safeReturn(?string $candidate, ?string $error = null): string
    {
        $origins = config('contact.return_origins');
        $target = ($origins[0] ?? rtrim((string) config('app.url'), '/')).self::FALLBACK_PATH;

        $url = filter_var((string) $candidate, FILTER_VALIDATE_URL) ? parse_url((string) $candidate) : null;

        if ($url && isset($url['scheme'], $url['host'])) {
            $origin = $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');

            if (in_array($origin, $origins, true)) {
                $target = $origin.($url['path'] ?? '/');
            }
        }

        return $error ? $target.'?error='.$error : $target;
    }
}
