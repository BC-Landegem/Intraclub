<?php

// Define app routes

use App\Action\Player\PlayerCreatorAction;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    // Redirect to Swagger documentation
    $app->get('/', \App\Action\Home\HomeAction::class)->setName('home');

    // API
    $app->group(
        '/api',
        function (RouteCollectorProxy $app) {
            $app->post('/players', PlayerCreatorAction::class);
            $app->get('/players/{player_id}', \App\Action\Player\PlayerReaderAction::class);
        }
    );
};