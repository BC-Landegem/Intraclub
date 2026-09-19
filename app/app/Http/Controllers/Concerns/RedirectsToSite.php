<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/*
 * De formulieren van de clubwebsite posten cross-origin vanaf een statische site
 * en krijgen altijd een 302 terug, nooit een foutstatus: een 419, 422, 429 of 500
 * belandt als rauwe JSON in de adresbalk van een vreemd domein.
 *
 * Waar de bezoeker landt stuurt het formulier zelf mee, zodat dit blijft werken
 * op localhost, op github.io en na een domeinswitch. Zonder de controle hieronder
 * is dat een open redirect, en staat er een phishing-doorverwijzing op het
 * clubdomein.
 *
 * Deze trait staat apart omdat hij door twee formulieren gebruikt wordt. Van alle
 * gedeelde stukken is dit het enige waar uiteenlopen een beveiligingsfout is —
 * de rest (honeypot, tijdslot) is zes regels en staat bewust dubbel, zodat elke
 * controller in één keer te lezen blijft. De allowlist komt daarom rechtstreeks
 * uit config/contact.php: het is dezelfde site, en twee lijsten die uit elkaar
 * lopen zetten een bezoeker op de verkeerde pagina.
 */
trait RedirectsToSite
{
    /** Pad op de terugval-origin waar de bezoeker landt. */
    abstract protected function fallbackPath(): string;

    /**
     * @param  string  $field  `return_ok` of `return_error` uit het formulier.
     */
    protected function back(Request $request, string $field, ?string $error = null): RedirectResponse
    {
        return redirect()->away($this->safeReturn($request->input($field), $error));
    }

    /*
     * Enkel scheme+host(+poort) uit de allowlist mag; het pad komt van de
     * bezoeker, de rest niet — dus geen querystring en geen fragment van buiten.
     */
    private function safeReturn(mixed $candidate, ?string $error = null): string
    {
        $origins = config('contact.return_origins');
        $target = ($origins[0] ?? rtrim((string) config('app.url'), '/')).$this->fallbackPath();

        // `return_error[]=…` komt hier als array binnen; een string-cast daarvan is een 500.
        $url = is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_URL) ? parse_url($candidate) : null;

        if ($url && isset($url['scheme'], $url['host'])) {
            $origin = $url['scheme'].'://'.$url['host'].(isset($url['port']) ? ':'.$url['port'] : '');

            if (in_array($origin, $origins, true)) {
                $target = $origin.($url['path'] ?? '/');
            }
        }

        return $error ? $target.'?error='.$error : $target;
    }
}
