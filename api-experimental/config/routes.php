<?php

declare(strict_types=1);

use App\Action\Auth\LoginAction;
use App\Action\Home\HomeAction;
use App\Action\Match\MatchCreatorAction;
use App\Action\Match\MatchListByRoundAction;
use App\Action\Match\MatchUpdaterAction;
use App\Action\Player\AttendanceUpdaterAction;
use App\Action\Player\PlayerCreatorAction;
use App\Action\Player\PlayerListAction;
use App\Action\Player\PlayerReaderAction;
use App\Action\Player\PlayerUpdaterAction;
use App\Action\Ranking\RankingReaderAction;
use App\Action\Round\RoundCreatorAction;
use App\Action\Round\RoundLatestAction;
use App\Action\Round\RoundLatestCalculatedAction;
use App\Action\Round\RoundListAction;
use App\Action\Round\RoundReaderAction;
use App\Action\Season\SeasonCalculatorAction;
use App\Action\Season\SeasonCreatorAction;
use App\Action\Season\SeasonStatisticsAction;
use App\Middleware\JwtAuthMiddleware;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->get('/', HomeAction::class)->setName('home');

    $app->group('/api', function (RouteCollectorProxy $group) {
        // Authentication (public)
        $group->post('/login', LoginAction::class);

        // Reads are public.
        $group->get('/players', PlayerListAction::class);
        $group->get('/players/{id}', PlayerReaderAction::class);
        $group->get('/seasons/latest/statistics', SeasonStatisticsAction::class);
        $group->get('/rounds', RoundListAction::class);
        $group->get('/rounds/latest', RoundLatestAction::class);
        $group->get('/rounds/latestCalculated', RoundLatestCalculatedAction::class);
        $group->get('/rounds/{id}', RoundReaderAction::class);
        $group->get('/rounds/{id}/matches', MatchListByRoundAction::class);
        $group->get('/rankings', RankingReaderAction::class);
        $group->get('/rankings/{type}', RankingReaderAction::class);

        // Writes require a valid JWT.
        $group->post('/players', PlayerCreatorAction::class)->add(JwtAuthMiddleware::class);
        $group->post('/players/{id}', PlayerUpdaterAction::class)->add(JwtAuthMiddleware::class);
        $group->post('/rounds/{id}/players/{playerId}', AttendanceUpdaterAction::class)->add(JwtAuthMiddleware::class);
        $group->post('/seasons', SeasonCreatorAction::class)->add(JwtAuthMiddleware::class);
        $group->post('/seasons/calculate', SeasonCalculatorAction::class)->add(JwtAuthMiddleware::class);
        $group->post('/rounds', RoundCreatorAction::class)->add(JwtAuthMiddleware::class);
        $group->post('/matches', MatchCreatorAction::class)->add(JwtAuthMiddleware::class);
        $group->post('/matches/{id}', MatchUpdaterAction::class)->add(JwtAuthMiddleware::class);
    });
};
