<?php

require __DIR__ . '/vendor/autoload.php';

$settings = require __DIR__ . '/src/settings.php';
$app = new \Slim\App($settings);

$dependencies = require __DIR__ . '/src/dependencies.php';
$dependencies($app);

$routes = require __DIR__ . '/src/routes.php';
$routes($app);

$app->run();
