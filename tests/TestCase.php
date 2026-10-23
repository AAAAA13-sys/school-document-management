<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $driver = $app['config']['database.default'];
        $database = $app['config']['database.connections.'.$driver.'.database'];
        if (! $app->environment('testing') || ! (($driver === 'sqlite' && $database === ':memory:') || ($driver === 'mysql' && $database === 'dms_test'))) {
            throw new \RuntimeException('Refusing database tests outside SQLite :memory: or MySQL dms_test. Clear cached configuration.');
        }

        return $app;
    }
}
