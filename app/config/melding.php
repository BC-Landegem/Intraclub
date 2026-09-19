<?php

/*
 * Meldformulier voor grensoverschrijdend gedrag (App\Http\Controllers\MeldingController).
 *
 * Alleen wat eigen is aan dit formulier staat hier. De redirect-allowlist en het
 * Turnstile-secret blijven in config/contact.php: dat is dezelfde site en
 * hetzelfde Cloudflare-sleutelpaar, en twee lijsten die uit elkaar lopen leveren
 * een melder een terugval op de verkeerde pagina op.
 *
 * De ontvanger staat hier wél apart, en dat is het hele punt van dit bestand.
 */

return [

    /*
     * Postbus van het Aanspreekpunt Integriteit.
     *
     * Bewust ZONDER terugval. Een melding over grensoverschrijdend gedrag mag
     * nooit bij het bestuur belanden, en een `env('MELDING_TO', 'info@…')` is
     * precies hoe dat ooit gebeurt: .env staat niet in git, dus bij een nieuwe
     * server of een herinstallatie is "vergeten" het standaardgeval. Staat deze
     * leeg, dan weigert het formulier zichtbaar (?error=unavailable) in plaats
     * van stil verkeerd te bezorgen.
     *
     * Wisselt de persoon, dan verandert deze variabele mee met INTEGRITY_NAME in
     * de Website-repo en de tekst op /club/aanspreekpunt-integriteit/. Vergeet je
     * deze, dan werkt het formulier gewoon door naar de vórige aanspreekpersoon
     * — daar waarschuwt niets voor, dus het staat in DEPLOY.md.
     */
    'to' => env('MELDING_TO'),

    // Hoe lang het formulier minstens open moet staan voor een inzending telt.
    'min_seconds' => 3,

    /*
     * Aantal inzendingen per IP per uur. Eigen emmer, los van het
     * contactformulier: anders kost een gezin dat vanmiddag drie keer het
     * contactformulier gebruikte vanavond een melding.
     */
    'max_per_hour' => 3,

];
