<?php

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/*
 * Het subdomein is in de praktijk het zaaltoestel; beheerders typen /admin.
 */
Route::redirect('/', '/zaal');

/*
 * Zaal-app (Angular): de gebouwde bestanden staan statisch in public/zaal en
 * worden door de webserver zelf geserveerd. Diepe links (bv. /zaal/login) vangt
 * public/zaal/.htaccess op. Deze route is het vangnet voor omgevingen zonder
 * mod_rewrite; ze wordt op Apache normaal nooit bereikt.
 */
Route::get('/zaal/{path?}', function (): Response {
    $index = public_path('zaal/index.html');

    abort_unless(file_exists($index), 404, 'De zaal-app is nog niet gebouwd (npm run build in /zaal).');

    return response(file_get_contents($index))->header('Content-Type', 'text/html');
})->where('path', '.*');
