<?php

namespace Tests\Feature;

use App\Jobs\ScanDocumentVersion;
use App\Models\User;
use App\Services\Scanner;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class BackupRestoreTest extends TestCase
{
    use DatabaseMigrations;

    public function test_mysql_dump_and_private_file_restore_recover_a_downloadable_version(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL-only backup/restore check.');
        }
        $this->assertSame('testing', app()->environment());
        $this->assertSame('dms_test', config('database.connections.mysql.database'));
        $finder = new ExecutableFinder;
        $mysql = $finder->find('mysql') ?: (is_file('C:/xampp/mysql/bin/mysql.exe') ? 'C:/xampp/mysql/bin/mysql.exe' : null);
        $dump = $finder->find('mysqldump') ?: (is_file('C:/xampp/mysql/bin/mysqldump.exe') ? 'C:/xampp/mysql/bin/mysqldump.exe' : null);
        if (! $mysql || ! $dump) {
            $this->markTestSkipped('Install native MySQL client tools for restore verification.');
        }
        Storage::fake('local');
        Queue::fake();
        $user = User::factory()->create(['role' => 'hr', 'source' => 'EMS', 'school_id' => 'RECOVERY-TEST', 'campus' => 'Test', 'active' => true]);
        DB::table('workspaces')->insert(['code' => 'EMS', 'name' => 'Recovery EMS', 'kind' => 'erp', 'status' => 'active', 'school_id' => 'RECOVERY-TEST', 'campus' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        $result = $this->actingAs($user)->postJson('/documents', ['title' => 'Restorable evidence', 'source' => 'EMS', 'classification' => 'Internal', 'submission_key' => 'restore-check', 'file' => UploadedFile::fake()->createWithContent('backup.txt', 'Synthetic recovery evidence')])->assertCreated()->json();
        $scanner = \Mockery::mock(Scanner::class);
        $scanner->shouldReceive('scan')->once()->andReturn('Clean');
        (new ScanDocumentVersion($result['version_id']))->handle($scanner);
        $version = DB::table('document_versions')->where('id', $result['version_id'])->first();
        $backup = tempnam(storage_path('framework/testing'), 'restore-check-');
        $bytes = Storage::disk('local')->get($version->storage_key);
        $cfg = config('database.connections.mysql');
        $args = ['--host='.$cfg['host'], '--port='.$cfg['port'], '--user='.$cfg['username'], 'dms_test'];
        $env = ['MYSQL_PWD' => (string) $cfg['password']];
        try {
            $process = new Process([$dump, '--single-transaction', '--hex-blob', '--result-file='.$backup, ...$args], base_path('tests/support'), $env);
            $process->setTimeout(30);
            $process->mustRun();
            $this->assertGreaterThan(0, filesize($backup));
            DB::table('documents')->where('id', $result['document_id'])->update(['title' => 'Changed after backup']);
            Storage::disk('local')->put($version->storage_key, 'corrupted after backup');
            $this->get('/files/'.$version->id)->assertConflict();
            DB::disconnect();
            $restore = new Process([$mysql, ...$args], base_path('tests/support'), $env, file_get_contents($backup), 30);
            $restore->mustRun();
            Storage::disk('local')->put($version->storage_key, $bytes);
            DB::reconnect();
            $this->assertDatabaseHas('documents', ['id' => $result['document_id'], 'title' => 'Restorable evidence']);
            $this->assertSame($version->checksum, hash_file('sha256', Storage::disk('local')->path($version->storage_key)));
            $this->get('/files/'.$version->id)->assertOk()->assertDownload('backup.txt');
            $this->assertDatabaseHas('audit_entries', ['document_id' => $result['document_id'], 'action' => 'document.downloaded']);
        } finally {
            @unlink($backup);
        }
    }
}
