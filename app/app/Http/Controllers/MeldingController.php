<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RedirectsToSite;
use App\Http\Controllers\Concerns\VerifiesTurnstile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/*
 * Meldformulier voor grensoverschrijdend gedrag (/club/melden/ op de clubwebsite).
 *
 * Mechanisch een tweeling van ContactController — cross-origin form-POST, altijd
 * een 302 terug, honeypot, tijdslot, begrenzer, Turnstile. Op vijf punten wijkt
 * het bewust af, en elk van die vijf komt voort uit hetzelfde: een melder heeft
 * geen tweede, anonieme weg. Er is geen bevestigingsmail, geen logregel met de
 * inhoud en geen tweede ontvanger, dus een melding die hier sneuvelt kent niemand
 * behalve de melder zelf.
 *
 *   1. De ontvanger komt alleen uit config/melding.php, nooit uit het request, en
 *      heeft geen terugval. Leeg = zichtbaar dicht, niet stil verkeerd bezorgen.
 *   2. Een onbereikbare Turnstile laat door met [ongeverifieerd] in het onderwerp;
 *      enkel een échte afkeuring gaat dicht. Een spammer kan onze verbinding naar
 *      Cloudflare niet platleggen, dus dat venster kan hij niet zelf openen — en
 *      honeypot, tijdslot en de begrenzer gelden nog altijd.
 *   3. De onderwerpregel draagt datum en uur, nooit de naam van de melder. Bij
 *      Beantwoorden gaat het onderwerp mee naar de bestuursmailbox (zie 5); de
 *      naam in de body kan ze wissen, het onderwerp niet.
 *   4. Geen IP, geen user-agent en geen Turnstile-details in de mail.
 *   5. `From` blijft MAIL_FROM_ADDRESS, dus een Beantwoorden belandt bij het
 *      bestuur. Bewust aanvaard — een eigen afzenderadres vraagt een alias bij de
 *      hostingpartij — maar de mail waarschuwt er bovenaan voor.
 *
 * Foutcodes in de querystring, zie /club/melden/ aan de sitekant:
 *
 *   ?error=unavailable  MELDING_TO staat niet in de .env — niets verstuurd
 *   ?error=bot          te snel ingevuld
 *   ?error=captcha      Turnstile keurde de inzending af
 *   ?error=validation   het bericht ontbreekt of is te lang
 *   ?error=throttle     te veel inzendingen vanaf hetzelfde IP
 *   ?error=mail         het versturen zelf mislukte
 */
class MeldingController extends Controller
{
    use RedirectsToSite;
    use VerifiesTurnstile;

    private const FALLBACK_PATH = '/club/melden/';

    public function __invoke(Request $request): RedirectResponse
    {
        /*
         * 0. Is er wel een ontvanger? Helemaal vooraan, nog vóór de begrenzer:
         *    anders verbruikt een verkeerd geconfigureerde server de drie
         *    pogingen van de melder, en staat die ook nog voor een dichte deur
         *    wanneer het euvel rechtgezet is.
         */
        $ontvanger = trim((string) config('melding.to'));

        if ($ontvanger === '') {
            report(new RuntimeException('MELDING_TO ontbreekt: het meldformulier weigert inzendingen.'));

            return $this->back($request, 'return_error', 'unavailable');
        }

        /*
         * 1. Snelheidsbegrenzing. Eigen emmer, en bewust hier en niet als
         *    throttle-middleware: die antwoordt met een JSON-429, en daar staat
         *    iemand die net een melding probeerde te doen dan naar te kijken.
         */
        $key = 'melding:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, config('melding.max_per_hour'))) {
            return $this->back($request, 'return_error', 'throttle');
        }

        RateLimiter::hit($key, 3600);

        // 2. Honeypot — stil doen alsof het gelukt is.
        if (filled($request->input('website'))) {
            return $this->back($request, 'return_ok');
        }

        // 3. Tijdslot. Vangt enkel de domme bots die binnen de seconde posten.
        $loaded = (int) $request->input('loaded_at');

        if ($loaded <= 0 || (now()->getTimestampMs() - $loaded) < config('melding.min_seconds') * 1000) {
            return $this->back($request, 'return_error', 'bot');
        }

        // 4. Turnstile. Afgekeurd gaat dicht, geen oordeel gaat door met een merk.
        $ongeverifieerd = false;

        if ($secret = config('contact.turnstile_secret')) {
            $oordeel = $this->verifyTurnstile($request, $secret, 'melding');

            if ($oordeel === false) {
                return $this->back($request, 'return_error', 'captcha');
            }

            $ongeverifieerd = $oordeel === null;
        }

        /*
         * 5. Pas nu valideren. Enkel het bericht is verplicht: wie anoniem wil
         *    melden, moet dat kunnen. Geen \r of \n in naam en contact — die horen
         *    in platte tekst thuis, en het contactveld gaat mogelijk de
         *    reply-to-header in.
         */
        try {
            $data = $request->validate([
                'message' => ['required', 'string', 'max:10000'],
                'name' => ['nullable', 'string', 'max:100', 'regex:/^[^\r\n]+$/'],
                'contact' => ['nullable', 'string', 'max:190', 'regex:/^[^\r\n]+$/'],
            ]);
        } catch (ValidationException $e) {
            return $this->back($request, 'return_error', 'validation');
        }

        /*
         * De applicatie draait op UTC, maar dit leest een mens in België. Enkel
         * deze ene weergave wordt omgezet; config('app.timezone') blijft met rust.
         */
        $tijdstip = now()->timezone('Europe/Brussels')->format('d-m-Y H:i');

        try {
            Mail::raw($this->body($data, $tijdstip), function ($mail) use ($data, $ontvanger, $tijdstip, $ongeverifieerd): void {
                $mail->to($ontvanger)
                    ->subject('[Melding]'.($ongeverifieerd ? '[ongeverifieerd]' : '').' '.$tijdstip);

                /*
                 * Enkel een reply-to als het contactveld écht een e-mailadres is.
                 * Het veld is vrije tekst: er kan even goed een telefoonnummer in
                 * staan, of "niet bellen voor 18u".
                 */
                if (filled($data['contact'] ?? null) && filter_var($data['contact'], FILTER_VALIDATE_EMAIL)) {
                    $mail->replyTo($data['contact']);
                }
            });
        } catch (Throwable $e) {
            report($e);

            return $this->back($request, 'return_error', 'mail');
        }

        return $this->back($request, 'return_ok');
    }

    protected function fallbackPath(): string
    {
        return self::FALLBACK_PATH;
    }

    /*
     * Platte tekst: die kan geen HTML-injectie dragen en leest in een mailbox even
     * goed. De waarschuwing staat bovenaan en niet onderaan — wie op Beantwoorden
     * wil duwen, moet ze gezien hebben vóór hij het bericht uitleest.
     *
     * @param  array<string, string|null>  $data
     */
    private function body(array $data, string $tijdstip): string
    {
        $streep = str_repeat('-', 70);

        return implode("\n", [
            'LET OP: "Beantwoorden" op deze mail gaat naar '.config('mail.from.address').', de',
            'bestuursmailbox — met dit bericht eronder gequote. Antwoord de melder',
            'rechtstreeks, in een nieuwe mail.',
            '',
            $streep,
            '',
            'Melding via het formulier op de website, '.$tijdstip.'.',
            '',
            'Naam:    '.(filled($data['name'] ?? null) ? $data['name'] : '(niet ingevuld)'),
            'Contact: '.(filled($data['contact'] ?? null) ? $data['contact'] : '(niet ingevuld)'),
            '',
            $streep,
            '',
            $data['message'],
        ]);
    }
}
