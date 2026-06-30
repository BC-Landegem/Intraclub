<?php

declare(strict_types=1);

use App\Middleware\CorsMiddleware;
use App\Middleware\SecurityHeadersMiddleware;
use Selective\BasePath\BasePathMiddleware;
use Slim\App;
use Slim\Middleware\ErrorMiddleware;

return function (App $app) {
    $app->addBodyParsingMiddleware();
    $app->addRoutingMiddleware();
    $app->add(BasePathMiddleware::class);
    $app->add(ErrorMiddleware::class);
    // Added last → outermost: applies (and CORS preflight short-circuits) to
    // every response, including errors.
    $app->add(SecurityHeadersMiddleware::class);
    $app->add(CorsMiddleware::class);
};
