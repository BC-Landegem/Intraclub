<?php

use App\Http\Controllers\Api\Archive\ArchivePlayerController;
use App\Http\Controllers\Api\Archive\ArchiveRoundController;
use App\Http\Controllers\Api\Archive\ArchiveSeasonController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\RoundController;
use App\Http\Controllers\Api\SeasonController;
use App\Http\Controllers\Api\ZaalController;
use App\Http\Controllers\DeployController;
use Illuminate\Support\Facades\Route;

/*
 * Publieke, alleen-lezen API voor de clubwebsite. Schrijfacties gebeuren in het
 * beheerspaneel of (later) in de zaal-app achter authenticatie.
 */

Route::get('rankings', [RankingController::class, 'index']);
Route::get('rankings/{category}', [RankingController::class, 'category']);

Route::get('rounds', [RoundController::class, 'index']);
Route::get('rounds/latest', [RoundController::class, 'latest']);
Route::get('rounds/latestCalculated', [RoundController::class, 'latestCalculated']);
Route::get('rounds/{round}', [RoundController::class, 'show']);
Route::get('rounds/{round}/matches', [RoundController::class, 'games']);

Route::get('players', [PlayerController::class, 'index']);
Route::get('players/{player}', [PlayerController::class, 'show']);

Route::get('seasons', [SeasonController::class, 'index']);
Route::get('seasons/latest/statistics', [SeasonController::class, 'statistics']);
Route::get('seasons/{season}/statistics', [SeasonController::class, 'statistics']);

/*
 * Archief van de jaargangen vóór het huidige systeem (2009-2023). Aparte paden, zodat
 * het contract van de bestaande endpoints ongemoeid blijft. Let op: het oude format
 * speelde met vaste teams in best-of-3, dus een wedstrijd heeft hier `team1`/`team2`
 * en soms maar twee sets.
 */
Route::prefix('archive')->group(function (): void {
    Route::get('seasons', [ArchiveSeasonController::class, 'index']);
    Route::get('seasons/{season}/rounds', [ArchiveSeasonController::class, 'rounds']);
    Route::get('seasons/{season}/standings', [ArchiveSeasonController::class, 'standings']);

    Route::get('rounds/{round}', [ArchiveRoundController::class, 'show']);

    Route::get('players', [ArchivePlayerController::class, 'index']);
    Route::get('players/{player}', [ArchivePlayerController::class, 'show']);
});

/*
 * Zaal-app: alles achter authenticatie (sessiecookie, zelfde origin).
 */
Route::middleware('web')->group(function (): void {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::prefix('zaal')->group(function (): void {
            Route::get('round', [ZaalController::class, 'currentRound']);
            Route::post('rounds', [ZaalController::class, 'storeRound']);
            Route::get('rounds/{round}', [ZaalController::class, 'show']);
            Route::get('rounds/{round}/fill-candidates', [ZaalController::class, 'fillCandidates']);
            Route::post('rounds/{round}/players', [ZaalController::class, 'storePlayer']);
            Route::post('rounds/{round}/attendance', [ZaalController::class, 'setAttendance']);
            Route::post('rounds/{round}/draw', [ZaalController::class, 'draw']);
            Route::post('rounds/{round}/games', [ZaalController::class, 'storeGame']);
            Route::put('games/{game}', [ZaalController::class, 'updateGame']);
        });
    });
});

/*
 * Deploy-endpoints, aangeroepen door GitHub Actions (.github/workflows/). Ze
 * staan hier en niet in web.php omdat de api-groep geen sessie en geen CSRF
 * gebruikt — server-naar-server dus. Zonder DEPLOY_TOKEN in .env geven ze 404.
 */
/*
 * Bewust géén throttle-middleware: die gebruikt de cache, en met
 * CACHE_STORE=database vraagt dat de `cache`-tabel — die bestaat pas ná migrate.
 * Het endpoint dat migrations draait mag niet afhangen van een gemigreerde
 * databank. De beveiliging is het token; zonder geldig token bestaat de route
 * niet (404), wat ook geen orakel geeft om op te brute-forcen.
 */
Route::prefix('deploy')->group(function (): void {
    Route::post('reset', [DeployController::class, 'reset']);
    Route::post('{task}', [DeployController::class, 'run'])
        ->whereIn('task', ['migrate', 'optimize', 'clear']);
});
