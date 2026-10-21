<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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

    public function test_simultaneous_identical_uploads_warn_instead_of_storing_twice(): void
    {
        $owner = User::factory()->create(['role' => 'admin', 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main', 'active' => true]);
        $writer = User::factory()->create(['role' => 'staff', 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main', 'active' => true]);
        $folder = (string) Str::uuid();
        DB::table('folders')->insert(['id' => $folder, 'owner_id' => $owner->id, 'name' => 'Concurrency', 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('folder_shares')->insert(['folder_id' => $folder, 'recipient_id' => $writer->id, 'permission' => 'Viewer', 'created_at' => now(), 'updated_at' => now()]);

        // Both workers upload the same bytes at the same instant. The owner publishes into
        // the shared folder, so whichever commits first becomes visible to the other.
        $responses = $this->runConcurrentUploads([
            ['user' => $owner->id, 'key' => 'concurrent-a', 'meta' => ['source' => 'EMS', 'title' => 'Owner copy', 'classification' => 'Internal', 'folder_id' => $folder]],
            ['user' => $writer->id, 'key' => 'concurrent-b', 'meta' => ['source' => 'EMS', 'title' => 'Writer copy', 'classification' => 'Internal']],
        ], 'Synthetic concurrent bytes', microtime(true) + 2.5);

        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 409], $statuses);
        $this->assertSame('duplicate_file', collect($responses)->firstWhere('status', 409)['code']);
        $this->assertSame(1, DB::table('documents')->count());
        $this->assertSame(1, DB::table('document_versions')->count());
        foreach (DB::table('document_versions')->pluck('storage_key') as $key) {
            Storage::disk('local')->delete($key);
        }
    }

    private function runConcurrentUploads(array $uploads, string $content, float $releaseAt): array
    {
        $connection = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'dms_test', 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'], 'CACHE_STORE' => 'array'];
        $workers = [];
        foreach ($uploads as $upload) {
            $process = new Process([PHP_BINARY, base_path('tests/support/upload-worker.php')], base_path(), $env,
                json_encode($upload + ['content' => $content, 'release_at' => $releaseAt]), 30);
            $process->start();
            $workers[] = $process;
        }

        return array_map(function ($process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), 'Upload worker failed: '.$process->getErrorOutput());

            return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }, $workers);
    }
}
