<?php

use App\Http\Controllers\Api\Archive\ArchiveSeasonController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\RecordController;
use App\Http\Controllers\Api\RoundController;
use App\Http\Controllers\Api\SeasonController;
use App\Http\Controllers\Api\ZaalController;
use App\Http\Controllers\DeployController;
use App\Http\Middleware\CurrentSeasonOnly;
use App\Http\Middleware\PublicCacheHeaders;
use App\Http\Middleware\RequireMember;
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
 * De grens van de publieke geschiedenis staat hieronder, in de routetabel zelf:
 *
 *   - CurrentSeasonOnly = speeldagen, wedstrijden, aanwezigheden en
 *     klassementsverloop bestaan publiek enkel voor het lopende seizoen;
 *   - RequireMember = een fiche zet de regels van één persoon bij elkaar en mag
 *     dus enkel voor wie nog lid is;
 *   - wat daarbuiten viel is verwijderd en niet achter een 403 geparkeerd: de
 *     speeldagen van het archief, de spelersindex van het archief en
 *     /players?members=0. Een route die 403 geeft is een uitnodiging, en git
 *     onthoudt het wel.
 *
 * Wat van een afgesloten seizoen wél publiek blijft: de eindstand (/rankings en
 * /seasons/{id}/statistics met ?members=0, /archive/seasons/{id}/standings), de
 * erelijst en /records.
 *
 * Er is bewust geen /v1/-prefix: deze API heeft twee consumenten (de clubwebsite
 * en de zaal-app) en die staan beide in eigen beheer. Een versie ernaast zetten
 * kost meer dan hem samen met de consumenten aanpassen.
 */
Route::middleware([PublicCacheHeaders::class, ValidateIncludes::class])->group(function (): void {
    /*
     * Eindstanden. Deze twee routes zijn de enige die élk seizoen mogen tonen, en
     * met ?members=0 ook de deelnemers die ondertussen gestopt zijn: een eindstand
     * met gaten erin is geen eindstand.
     */
    // ?category= mag ook op /rankings; het pad is de kortere vorm daarvan.
    Route::get('rankings', [RankingController::class, 'index']);
    Route::get('rankings/{category}', [RankingController::class, 'category']);

    Route::get('seasons', [SeasonController::class, 'index']);
    Route::get('seasons/{season}/statistics', [SeasonController::class, 'statistics']);

    /*
     * Clubrecords. Enige endpoint waar '?season=' weglaten "alle seizoenen"
     * betekent en niet het lopende: een clubrecord over één seizoen is er geen.
     * Een record is een onderscheiding en geen profiel, dus dit blijft compleet.
     */
    Route::get('records', [RecordController::class, 'index']);

    /*
     * Speeldagen, en dus ook wedstrijden en aanwezigheden: lopend seizoen.
     *
     * `include=attendances` hangt aan elke speeldag dezelfde aanwezigheidsrijen als
     * /rounds/{round}, wat een seizoen aan speeldagpagina's in één call zet. Diezelfde
     * include over een afgesloten seizoen was het volledigste dossier in de hele API
     * (wie wanneer aanwezig was, met dagscore) — daarom staat de seizoensgrens hier.
     */
    Route::middleware(CurrentSeasonOnly::class)->group(function (): void {
        Route::get('rounds', [RoundController::class, 'index'])->defaults('include', ['attendances']);
        Route::get('rounds/{round}', [RoundController::class, 'show']);
        Route::get('rounds/{round}/games', [RoundController::class, 'games']);
    });

    /*
     * Spelers. De lijst is het huidige ledenbestand — er is geen ?members=0, want
     * dat was een bladerbare lijst van vertrokken leden.
     *
     * De fiche geeft het lopende seizoen volledig, plus per afgesloten seizoen de
     * vijf kolommen van de eindstand (plaats, gemiddelde, sets, matchen, aanwezig).
     * Wedstrijden, klassementsverloop en partnerbalans blijven binnen het lopende
     * seizoen: ontdubbeld over de leden van een afgesloten seizoen stond de hele
     * speeldaggeschiedenis er anders weer, met alle namen en setstanden.
     */
    Route::get('players', [PlayerController::class, 'index']);

    Route::middleware([RequireMember::class, CurrentSeasonOnly::class])->group(function (): void {
        // De seizoensgrens staat ook op de fiche zelf, en niet enkel op de drie
        // sub-resources: `?include=games,ranking_history` geeft anders langs dezelfde
        // weg terug wat /players/{id}/games net tegenhoudt.
        Route::get('players/{player}', [PlayerController::class, 'show'])
            ->defaults('include', ['games', 'ranking_history']);
        Route::get('players/{player}/games', [PlayerController::class, 'games']);
        Route::get('players/{player}/ranking-history', [PlayerController::class, 'rankingHistory']);
        Route::get('players/{player}/pairings', [PlayerController::class, 'pairings']);
    });

    /*
     * Archief van de jaargangen vóór het huidige systeem (2009-2023). Alles daarvan
     * is per definitie afgesloten, dus blijft enkel de eindstand over — de index om
     * de zeventien standen te vinden, en de stand zelf. Aparte paden omdat het oude
     * format met vaste teams in best-of-3 speelde, waardoor een gemiddelde van toen
     * een ander getal is dan een gemiddelde van nu.
     *
     * /archive/seasons/{id}/rounds, /archive/rounds/{id} en /archive/players zijn
     * weg: speeldagen van vroeger, en de eindstand gekanteld per persoon.
     */
    Route::prefix('archive')->group(function (): void {
        Route::get('seasons', [ArchiveSeasonController::class, 'index']);
        Route::get('seasons/{season}/standings', [ArchiveSeasonController::class, 'standings']);
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
