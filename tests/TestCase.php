<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        $driver = $app['config']['database.default'];
        $database = $app['config']['database.connections.'.$driver.'.database'];
        if (! empty($app['config']['database.connections.'.$driver.'.url'])) {
            throw new \RuntimeException('Refusing database tests with a connection URL that could override the isolated schema.');
        }
        if (! $app->environment('testing') || ! (($driver === 'sqlite' && $database === ':memory:') || ($driver === 'mysql' && $database === 'dms_test'))) {
            throw new \RuntimeException('Refusing database tests outside SQLite :memory: or MySQL dms_test. Clear cached configuration.');
        }
        if ($driver === 'mysql') {
            // Configuration alone is insufficient: bootstrap may have resolved a
            // connection before a caller changed its configured database name.
            try {
                $selected = $app['db']->connection()->selectOne('SELECT DATABASE() AS selected_schema');
            } catch (\Throwable) {
                throw new \RuntimeException('Refusing database tests: the isolated MySQL connection could not be verified.');
            }
            if (($selected->selected_schema ?? null) !== 'dms_test') {
                throw new \RuntimeException('Refusing database tests: the active connection is not dms_test.');
            }
        }

        return $app;
    }
}
