<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MysqlConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        // Skip before migrations so the ordinary SQLite suite stays fast.
        parent::setUp();
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Run with phpunit.mysql.xml for real row-lock checks.');
        }
        $this->assertSame('dms_test', config('database.connections.mysql.database'));
        $this->assertSame('testing', app()->environment());
    }

    public function test_simultaneous_replays_create_one_receipt_and_revision_conflicts_are_bounded(): void
    {
        $connector = DB::table('connectors')->insertGetId(['name' => 'Concurrency', 'school_id' => 'PLP', 'campus' => 'Main', 'source' => 'EMS', 'active' => true, 'token_hash' => hash('sha256', 'concurrency'), 'created_at' => now(), 'updated_at' => now()]);
        $base = ['event_id' => 'replay', 'record_type' => 'staff', 'record_id' => 'synthetic', 'revision' => 1, 'operation' => 'upsert', 'payload' => ['value' => 'same']];
        $responses = $this->runConcurrent($connector, [$base, $base, $base]);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([200, 200, 201], $statuses);
        $this->assertSame(1, count(array_unique(array_column(array_column($responses, 'body'), 'history_id'))));
        $this->assertDatabaseCount('history_receipts', 1);
        $responses = $this->runConcurrent($connector, [array_replace($base, ['event_id' => 'next-a', 'revision' => 2]), array_replace($base, ['event_id' => 'next-b', 'revision' => 2])]);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertDatabaseCount('history_receipts', 2);
        $this->assertDatabaseHas('source_snapshots', ['revision' => 2]);
    }

    private function runConcurrent(int $connector, array $events): array
    {
        $connection = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'dms_test', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array'];
        $workers = [];
        foreach ($events as $event) {
            $process = new Process([PHP_BINARY, base_path('tests/support/history-worker.php')], base_path(), $env, json_encode(compact('connector', 'event')), 20);
            $process->start();
            $workers[] = $process;
        }

        return array_map(function ($process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Worker failed; inspect the local test log.');

            return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }, $workers);
    }
}
