<?php

namespace Tests\Feature;

use App\Jobs\ScanDocumentVersion;
use App\Models\User;
use App\Services\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClamAvIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_engine_scans_a_queued_benign_file_and_blocks_eicar(): void
    {
        if (getenv('DMS_REAL_CLAMAV_TESTS') !== '1') {
            $this->markTestSkipped('Opt in with DMS_REAL_CLAMAV_TESTS=1 after installing official signatures.');
        }
        $this->assertTrue(config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:' || config('database.connections.mysql.database') === 'dms_test');
        Storage::fake('local');
        Queue::fake();
        $user = User::factory()->create(['role' => 'hr', 'source' => 'EMS', 'school_id' => 'TEST', 'campus' => 'Test', 'active' => true]);
        $result = $this->actingAs($user)->postJson('/documents', ['title' => 'Real engine check', 'source' => 'EMS', 'classification' => 'Internal', 'submission_key' => 'real-engine', 'file' => UploadedFile::fake()->createWithContent('benign.txt', 'Synthetic DMS integration check')])->assertCreated()->assertJsonPath('scan_status', 'Pending')->json();
        Queue::assertPushed(ScanDocumentVersion::class);
        $this->get('/files/'.$result['version_id'])->assertStatus(423);
        (new ScanDocumentVersion($result['version_id']))->handle(new Scanner);
        $this->get('/files/'.$result['version_id'])->assertOk();
        $this->assertDatabaseHas('audit_entries', ['action' => 'document.scanned', 'document_id' => $result['document_id']]);
        // EICAR is the standard harmless antivirus test string, assembled to avoid source-file detection.
        $eicar = 'X5O!P%@AP[4'.'\\PZX54(P^)7CC)7}'.'$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*';
        Storage::disk('local')->put('engine-test.txt', $eicar);
        try {
            $path = Storage::disk('local')->path('engine-test.txt');
            $status = (new Scanner)->scan($path);
            $this->assertContains($status, ['Quarantined', 'Failed']);
            if ($status === 'Failed') {
                // Windows Defender can deny EICAR reads (ERROR_VIRUS_INFECTED 225) before ClamAV.
                $this->assertSame('Windows', PHP_OS_FAMILY);
                $this->assertFalse(@file_get_contents($path), 'A readable EICAR file must be quarantined by ClamAV.');
            }
        } finally {
            Storage::disk('local')->delete('engine-test.txt');
        }
    }
}
