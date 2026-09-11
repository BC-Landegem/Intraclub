<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // Vangnet voor tests/bootstrap.php: loopt die om wat voor reden ook niet,
    // dan draaien de tests op de MariaDB uit de container-omgeving en dropt
    // RefreshDatabase de echte databank. Deze controle valt vóór
    // setUpTraits(), dus vóór de eerste migrate:fresh.
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = config('database.default');

        if ($connection !== 'sqlite') {
            static::fail("Tests draaien op verbinding '{$connection}' in plaats van sqlite; afgebroken voor RefreshDatabase de databank wist.");
        }
    }
}
