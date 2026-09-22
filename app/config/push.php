<?php

/*
 * Pushberichten (Web Push met VAPID) voor de clubwebsite.
 *
 * De site (Astro, ander domein) abonneert de browser van de bezoeker en meldt
 * dat abonnement hier aan via PUT /api/push/subscriptions; de berichten zelf
 * vertrekken vanuit deze app: met de hand vanuit het beheerspaneel (onderwerp
 * `club`) of automatisch zodra een speeldag berekend is (onderwerp `intraclub`).
 * Het contract met de site staat in de README van de Website-repo onder
 * "Databronnen · Pushberichten"; de kant van deze app in README.md hier.
 */

return [

    /*
     * Het VAPID-sleutelpaar. Genereer het één keer met `php artisan push:vapid`
     * en bewaar het: een nieuw paar maakt elk bestaand abonnement waardeloos.
     * De publieke helft moet letterlijk dezelfde zijn als PUBLIC_VAPID_PUBLIC_KEY
     * in de build van de site. Eén paar per omgeving, zodat een testbericht
     * nooit bij de echte abonnees belandt.
     *
     * Zonder private sleutel staat het versturen uit: abonnementen worden nog
     * wel aangenomen, maar er vertrekt niets en het beheerspaneel zegt dat.
     */
    'vapid' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        // Contactadres dat de pushdiensten te zien krijgen bij problemen.
        'subject' => env('VAPID_SUBJECT', 'mailto:info@bclandegem.be'),
    ],

    /*
     * Waar de site staat. De links in een bericht worden hiermee absoluut
     * gemaakt: de service worker lost een relatief pad op tegen zijn origin en
     * verliest dan de base-path van een github.io-build. Zonder slash op het
     * einde.
     */
    'site_url' => rtrim((string) env('PUSH_SITE_URL', 'https://www.bclandegem.be'), '/'),

    /*
     * De onderwerpen waarop een toestel apart kan intekenen. De sleutel is wat
     * over de lijn gaat en wat de site in PUSH_TOPICS kent; komt er een bij, dan
     * aan beide kanten. `ttl` is hoe lang de pushdienst een bericht bewaart voor
     * een toestel dat offline is, in seconden: een afgelasting van vorige week
     * hoeft niet meer aan te komen.
     *
     * `tag` laat een nieuw bericht het vorige met dezelfde tag vervangen. Een
     * vaste tag betekent "van dit onderwerp hangt er nooit meer dan één": de
     * stand van vorige week hoeft niet meer naast die van vandaag. `null` is
     * niet "geen tag" maar "een tag per bericht" (`club-12`, zie
     * PushMessage::payload) — twee clubberichten blijven zo naast elkaar staan,
     * maar een retry van hetzelfde bericht komt er niet een tweede keer bij.
     */
    'topics' => [
        'club' => [
            'label' => 'Clubberichten',
            'ttl' => 4 * 86400,
            'tag' => null,
        ],
        'intraclub' => [
            'label' => 'Intraclub',
            'ttl' => 2 * 86400,
            'tag' => 'intraclub',
        ],
    ],

    /*
     * Hostnamen van pushdiensten die we als endpoint aanvaarden (de host van het
     * endpoint moet eindigen op één van deze). Het endpoint is een URL waar deze
     * server berichten naartoe POST, dus zonder lijst is dit een open relais
     * naar om het even welke server. Chrome, Edge, Brave, Opera, Vivaldi en
     * Samsung Internet lopen via FCM; Firefox via Mozilla; Safari via Apple;
     * oudere Edge via Windows Notification Service. Een browser die hier niet
     * bij staat krijgt 422 en op de site "De server kon je keuze niet bewaren".
     * Extra hosts via PUSH_ENDPOINT_HOSTS, komma-gescheiden.
     */
    'endpoint_hosts' => array_values(array_filter(array_merge([
        'fcm.googleapis.com',
        'push.services.mozilla.com',
        'push.apple.com',
        'notify.windows.com',
    ], array_map('trim', explode(',', (string) env('PUSH_ENDPOINT_HOSTS', '')))))),

    // Aantal PUT/DELETE-verzoeken per IP per minuut op het abonnementen-endpoint.
    'max_per_minute' => 30,

    /*
     * Hoe oud een speeldag hoogstens mag zijn om er automatisch een bericht over
     * te sturen, in dagen. Beschermt tegen een import of een databank-reset die
     * in één beweging twintig speeldagen "berekent", en is meteen de enige grens:
     * een seizoenscheck staat er bewust niet naast, zie RoundNotifier.
     */
    'round_max_age_days' => 3,

];
