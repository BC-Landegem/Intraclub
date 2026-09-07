<?php
use Slim\App;
return function (App $app) {
    $container = $app->getContainer(); 
    $container['db'] = function ($c) {
        $db = $c['settings']['db'];
        $pdo = new PDO('mysql:host=localhost; dbname=' . $db['dbname'].'; charset=UTF8', $db['user'], $db['pass']);

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    };
};