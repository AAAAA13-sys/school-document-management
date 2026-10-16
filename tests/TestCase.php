<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['dms.scanner' => 'test-clamscan']);
        \Illuminate\Support\Facades\Process::fake([
            '*' => \Illuminate\Support\Facades\Process::result(exitCode: 0),
        ]);
    }
}
