<?php

/*
 * Contactformulier van de clubwebsite (App\Http\Controllers\ContactController).
 *
 * De site is statisch en staat op een ander domein, dus het formulier post
 * cross-origin naar deze app en wordt daarna teruggestuurd naar de pagina waar
 * de bezoeker vandaan kwam. Welke domeinen dat mogen zijn staat hieronder — dat
 * is de enige rem op een open redirect.
 */

return [

    // Postbus waar de berichten aankomen.
    'to' => env('CONTACT_TO', 'info@bclandegem.be'),

    /*
     * Origins waar de bezoeker na het versturen naartoe mag. Komma-gescheiden,
     * zonder slash op het einde; de eerste is de terugval als het formulier iets
     * anders meestuurt. Zelfde vorm als CORS_ALLOWED_ORIGINS, maar bewust een
     * aparte lijst: dit is een redirectdoel, geen leestoegang.
     */
    'return_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CONTACT_RETURN_ORIGINS', ''))
    ))),

    /*
     * Cloudflare Turnstile. Leeg = verificatie overgeslagen, zodat het endpoint
     * live kan vóór het Cloudflare-account bestaat. Aanzetten is twee variabelen
     * tegelijk: deze, en TURNSTILE_SITE_KEY bij de site. Eén van de twee is
     * ofwel een onbeschermd formulier, ofwel een formulier dat alles weigert.
     */
    'turnstile_secret' => env('TURNSTILE_SECRET'),

    // Hoe lang het formulier minstens open moet staan voor een inzending telt.
    'min_seconds' => 3,

    // Aantal inzendingen per IP per uur.
    'max_per_hour' => 3,

];
