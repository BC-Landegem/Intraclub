<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/*
 * Cloudflare Turnstile, gedeeld door de twee formulieren van de clubwebsite.
 *
 * Deze trait geeft alleen een oordeel terug en neemt bewust geen beslissing: wat
 * er moet gebeuren als Cloudflare onbereikbaar is, verschilt per formulier en
 * hoort zichtbaar in de controller te staan, niet verstopt in een vlag hier.
 */
trait VerifiesTurnstile
{
    /**
     * @param  string  $action  Moet overeenkomen met de `data-action` op de widget.
     * @return bool|null true = goedgekeurd, false = afgekeurd, null = geen oordeel
     *                   (Cloudflare onbereikbaar of zelf stuk).
     */
    private function verifyTurnstile(Request $request, string $secret, string $action): ?bool
    {
        try {
            $response = Http::asForm()
                ->timeout(5)
                ->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                    'secret' => $secret,
                    'response' => $request->input('cf-turnstile-response'),
                    'remoteip' => $request->ip(),
                ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        /*
         * Enkel een 5xx is "geen oordeel". Een 4xx (te groot token, te veel
         * requests) kan een inzender zelf uitlokken; die als geen oordeel tellen
         * zou het meldformulier voor hem openzetten.
         */
        if ($response->serverError()) {
            report(new RuntimeException('Turnstile antwoordde met HTTP '.$response->status()));

            return null;
        }

        if ($response->clientError()) {
            return false;
        }

        $json = $response->json();

        // Een 2xx zonder bruikbare JSON (proxy-pagina, lege body) is ook geen oordeel.
        if (! is_bool($json['success'] ?? null)) {
            report(new RuntimeException('Turnstile antwoordde zonder leesbaar oordeel.'));

            return null;
        }

        /*
         * De action komt uit het antwoord van Cloudflare en niet uit het request:
         * zonder deze controle is een token dat op het contactformulier gemint is
         * ook geldig op het meldformulier, want beide gebruiken hetzelfde
         * sleutelpaar. Veel houdt dat niet tegen — tokens zijn eenmalig en leven
         * enkele minuten — maar het is de enige manier om de twee formulieren bij
         * Cloudflare uit elkaar te houden, en dat kan achteraf niet gesplitst.
         */
        return $json['success'] && ($json['action'] ?? null) === $action;
    }
}
