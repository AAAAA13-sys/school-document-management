<?php

namespace Tests\Feature;

use App\Http\Controllers\OperationsHealthController;
use App\Models\User;
use App\Services\BackupArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class OperationsReadinessTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role): User
    {
        return User::factory()->create(['role' => $role, 'source' => $role === 'super_admin' ? null : 'EMS', 'school_id' => 'PLP', 'campus' => 'Main', 'active' => true]);
    }

    public function test_backup_is_disabled_until_operator_configuration_is_supplied(): void
    {
        config(['operations.backup_enabled' => false]);
        $this->artisan('dms:backup')->assertFailed();
    }

    public function test_health_requires_active_super_admin_and_reports_queue_state(): void
    {
        Storage::fake('local');
        $request = Request::create('/admin/health');
        $request->setUserResolver(fn () => $this->account('super_admin'));
        $response = app(OperationsHealthController::class)($request);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getData(true)['database']);
        $this->assertSame('not_measured', $response->getData(true)['queue']['worker_liveness']);
        $request->setUserResolver(fn () => $this->account('staff'));
        try {
            app(OperationsHealthController::class)($request);
            $this->fail('Staff health access accepted.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_archive_verification_decrypts_and_checks_every_entry(): void
    {
        config(['operations.backup_password' => str_repeat('S', 40)]);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dms-test-'.Str::uuid().'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::EXCL));
        $sql = 'CREATE TABLE example (id INT);';
        $manifest = json_encode(['format' => 1, 'created_at' => now()->toIso8601String(), 'checksums' => ['database.sql' => hash('sha256', $sql)]]);
        foreach (['database.sql' => $sql, 'manifest.json' => $manifest] as $name => $bytes) {
            $zip->addFromString($name, $bytes);
            $zip->setEncryptionName($name, ZipArchive::EM_AES_256, str_repeat('S', 40));
        }
        $zip->close();
        try {
            $verified = app(BackupArchive::class)->verify($path);
            $this->assertSame(hash('sha256', $sql), $verified['checksums']['database.sql']);
            config(['operations.backup_password' => str_repeat('W', 40)]);
            try {
                app(BackupArchive::class)->verify($path);
                $this->fail('Wrong password accepted.');
            } catch (RuntimeException) {
                $this->assertTrue(true);
            }
        } finally {
            unlink($path);
        }
    }

    public function test_legal_hold_records_and_releases_without_deleting_audit_entries(): void
    {
        $sa = $this->account('super_admin');
        $this->artisan('dms:audit-hold', ['administrator' => $sa->email, '--reason' => 'Pending privacy investigation.'])->assertSuccessful();
        $hold = DB::table('audit_legal_holds')->first();
        $this->assertNotNull($hold);
        $this->assertDatabaseHas('audit_entries', ['action' => 'audit.legal_hold.created']);
        $this->artisan('dms:audit-hold', ['administrator' => $sa->email, '--reason' => 'Investigation completed.', '--release' => $hold->id])->assertSuccessful();
        $this->assertNotNull(DB::table('audit_legal_holds')->where('id', $hold->id)->value('released_at'));
        $this->assertDatabaseCount('audit_entries', 2);
    }

    public function test_quarterly_readiness_does_not_claim_a_completed_restore(): void
    {
        Storage::fake('local');
        $this->artisan('dms:restore-readiness')->expectsOutputToContain('Quarterly restore drill is due')->assertFailed();
        $this->assertFalse(Storage::disk('local')->exists('operations/status.json'));
    }
}
