<?php

use App\Http\Controllers\Api\Archive\ArchivePlayerController;
use App\Http\Controllers\Api\Archive\ArchiveRoundController;
use App\Http\Controllers\Api\Archive\ArchiveSeasonController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\RecordController;
use App\Http\Controllers\Api\RoundController;
use App\Http\Controllers\Api\SeasonController;
use App\Http\Controllers\Api\ZaalController;
use App\Http\Controllers\DeployController;
use App\Http\Middleware\PublicCacheHeaders;
use App\Http\Middleware\ValidateIncludes;
use Illuminate\Support\Facades\Route;

/*
 * Publieke, alleen-lezen API voor de clubwebsite. Schrijfacties gebeuren in het
 * beheerspaneel of in de zaal-app achter authenticatie.
 *
 * Conventies, overal hetzelfde:
 *   - veldnamen in snake_case, booleans als echte booleans;
 *   - collecties in `data`, met `meta` voor het seizoen en de speeldag waarop de
 *     gegevens staan;
 *   - filters als queryparameters, niet als aparte routes;
 *   - `?season=` slikt een id of `current`; weglaten = het lopende seizoen;
 *   - een onbekend seizoen, speeldag of speler geeft 404, geen stille terugval;
 *   - `?include=` mag enkel wat de route hieronder met `defaults('include', …)`
 *     opsomt; al de rest geeft 422, ook op een route zonder includes. Een
 *     genegeerde include kost een consument honderden requests zonder dat er
 *     iets waarschuwt.
 *
 * Er is bewust geen /v1/-prefix: deze API heeft twee consumenten (de clubwebsite
 * en de zaal-app) en die staan beide in eigen beheer. Een versie ernaast zetten
 * kost meer dan hem samen met de consumenten aanpassen.
 */
Route::middleware([PublicCacheHeaders::class, ValidateIncludes::class])->group(function (): void {
    // ?category= mag ook op /rankings; het pad is de kortere vorm daarvan.
    Route::get('rankings', [RankingController::class, 'index']);
    Route::get('rankings/{category}', [RankingController::class, 'category']);

    // `include=attendances` geeft de aanwezigheden van élke speeldag in één call:
    // dezelfde rijen als op /rounds/{round}, waar een heel seizoen anders twintig
    // requests voor nodig had — of, erger, één per speler.
    Route::get('rounds', [RoundController::class, 'index'])->defaults('include', ['attendances']);
    Route::get('rounds/{round}', [RoundController::class, 'show']);
    Route::get('rounds/{round}/games', [RoundController::class, 'games']);

    Route::get('players', [PlayerController::class, 'index']);
    Route::get('players/{player}', [PlayerController::class, 'show'])
        ->defaults('include', ['games', 'ranking_history']);
    Route::get('players/{player}/games', [PlayerController::class, 'games']);
    Route::get('players/{player}/ranking-history', [PlayerController::class, 'rankingHistory']);
    Route::get('players/{player}/pairings', [PlayerController::class, 'pairings']);

    Route::get('seasons', [SeasonController::class, 'index']);
    Route::get('seasons/{season}/statistics', [SeasonController::class, 'statistics']);

    /*
     * Clubrecords. Enige endpoint waar '?season=' weglaten "alle seizoenen"
     * betekent en niet het lopende: een clubrecord over één seizoen is er geen.
     */
    Route::get('records', [RecordController::class, 'index']);

    /*
     * Archief van de jaargangen vóór het huidige systeem (2009-2023). Aparte paden
     * omdat het oude format met vaste teams in best-of-3 speelde: een wedstrijd
     * heeft hier `team1`/`team2` in plaats van duo's die per set wisselen, en soms
     * maar twee sets.
     */
    Route::prefix('archive')->group(function (): void {
        Route::get('seasons', [ArchiveSeasonController::class, 'index']);
        Route::get('seasons/{season}/rounds', [ArchiveSeasonController::class, 'rounds'])
            ->defaults('include', ['games']);
        Route::get('seasons/{season}/standings', [ArchiveSeasonController::class, 'standings']);

        Route::get('rounds/{round}', [ArchiveRoundController::class, 'show']);

        Route::get('players', [ArchivePlayerController::class, 'index'])
            ->defaults('include', ['seasons']);
        Route::get('players/{player}', [ArchivePlayerController::class, 'show'])
            ->defaults('include', ['games']);
    });
});

/*
 * Zaal-app: alles achter authenticatie (sessiecookie, zelfde origin).
 *
 * Deze payloads staan bewust nog in camelCase. Het is een interne vorm met één
 * consument, hij moet op een speeldagavond werken, en hij levert de site niets
 * op. Omzetten kan later, samen met de Angular-app.
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
