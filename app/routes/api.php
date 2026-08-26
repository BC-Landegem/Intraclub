<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PlayerController;
use App\Http\Controllers\Api\RankingController;
use App\Http\Controllers\Api\RoundController;
use App\Http\Controllers\Api\SeasonController;
use App\Http\Controllers\Api\ZaalController;
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
 * Zaal-app: alles achter authenticatie (sessiecookie, zelfde origin).
 */
Route::middleware('web')->group(function (): void {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::prefix('zaal')->group(function (): void {
            Route::get('round', [ZaalController::class, 'currentRound']);
            Route::get('rounds/{round}', [ZaalController::class, 'show']);
            Route::post('rounds/{round}/players', [ZaalController::class, 'storePlayer']);
            Route::post('rounds/{round}/attendance', [ZaalController::class, 'setAttendance']);
            Route::post('rounds/{round}/draw', [ZaalController::class, 'draw']);
            Route::post('rounds/{round}/games', [ZaalController::class, 'storeGame']);
            Route::put('games/{game}', [ZaalController::class, 'updateGame']);
        });
    });
});
