<?php

/*
 * Instellingen voor de deploy-endpoints (App\Http\Controllers\DeployController).
 * Die worden door GitHub Actions aangeroepen, omdat er op de shared hosting geen
 * SSH en dus geen artisan beschikbaar is.
 *
 * LET OP: na `php artisan optimize` staat de configuratie in de cache en worden
 * deze env-waarden niet meer gelezen. Wijzig je .env, dan moet `optimize`
 * opnieuw draaien voor de wijziging aankomt.
 */

return [

    // Bearer-token dat de endpoints beschermt. Leeg = de routes bestaan niet (404).
    'token' => env('DEPLOY_TOKEN'),

];
