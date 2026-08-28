<?php

/*
 * De publieke API wordt vanuit de clubwebsite (ander subdomein) aangesproken,
 * dus die origins moeten expliciet toegelaten worden. Zet extra origins via
 * CORS_ALLOWED_ORIGINS in .env, komma-gescheiden.
 *
 * bc-landegem.github.io staat erbij omdat de nieuwe site daar gebouwd wordt en
 * de API van hieruit aanspreekt. Die origin mag eruit zodra de site op het eigen
 * domein staat.
 */

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            'https://www.bclandegem.be,https://bclandegem.be,https://bc-landegem.github.io'
        ))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // Nodig voor de zaal-app: die logt in met een sessiecookie (Sanctum SPA-mode).
    'supports_credentials' => true,

];
