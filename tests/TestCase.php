<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Symfony manda «Accept-Language: en-us» por defecto; las pruebas son de un navegador en español
        $this->withHeader('Accept-Language', 'es-CO,es;q=0.9');
    }
}
