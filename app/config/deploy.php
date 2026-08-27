<?php

/*
 * Instellingen voor de deploy-endpoints (App\Http\Controllers\DeployController).
 * Die worden door GitHub Actions aangeroepen, omdat er op de shared hosting geen
 * SSH en dus geen artisan beschikbaar is.
 *
 * LET OP: na `php artisan optimize` staat de configuratie in de cache en worden
 * deze env-waarden niet meer gelezen. Wijzig je .env, dan moet `optimize`
 * opnieuw draaien voor de wijziging aankomt — ook voor `allow_reset`. Daarom is
 * het ontbreken van het snapshotbestand de tweede, cache-onafhankelijke rem.
 */

return [

    // Bearer-token dat de endpoints beschermt. Leeg = de routes bestaan niet (404).
    'token' => env('DEPLOY_TOKEN'),

    // Mag de databank gewist en uit de snapshot herladen worden? Uit bij cutover.
    'allow_reset' => (bool) env('INTRACLUB_ALLOW_RESET', false),

    // Pad naar de snapshot, relatief aan storage/. Handmatig via FTP geplaatst.
    'snapshot' => env('INTRACLUB_SNAPSHOT', 'app/private/cutover.sql.gz'),

    // Hoeveel bak_-reeksen bewaard blijven na een reset (inclusief de nieuwste).
    'backup_sets_kept' => (int) env('INTRACLUB_BACKUP_SETS', 2),

];
