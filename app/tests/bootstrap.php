<?php

// In Docker staan DB_CONNECTION en DB_HOST in de container-omgeving, en die erft
// phpunit rechtstreeks. Ze komen in $_SERVER terecht, en Laravel leest $_SERVER
// vóór putenv; PHPUnit's <env> schrijft enkel putenv, ook met force="true". De
// tests zouden dus op MariaDB lopen en RefreshDatabase zou de echte databank
// droppen. Daarom hier, als enige punt dat vóór de app-boot valt.
foreach (['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'] as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require __DIR__.'/../vendor/autoload.php';
