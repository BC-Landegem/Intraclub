<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_de_hoofdpagina_stuurt_naar_de_zaal_app(): void
    {
        $this->get('/')->assertRedirect('/zaal');
    }
}
