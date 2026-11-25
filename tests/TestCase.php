<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication {
        createApplication as baseCreateApplication;
    }

    /**
     * Tests use RefreshDatabase, which drops every table. phpunit.xml points them at an in-memory
     * SQLite database, but a cached config (php artisan config:cache / optimize) overrides that and
     * would aim them at the real database. Refuse to run before any trait touches the database.
     */
    public function createApplication()
    {
        $app = $this->baseCreateApplication();

        $config = $app['config'];
        if ($config->get('database.default') !== 'sqlite' || $config->get('database.connections.sqlite.database') !== ':memory:') {
            throw new RuntimeException(
                'Tests must run on the in-memory SQLite database. Run `php artisan config:clear` first.'
            );
        }

        return $app;
    }
}
