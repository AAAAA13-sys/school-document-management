<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\FileScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SddSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'active' => true, 'school_id' => 'DEMO-SCHOOL', 'campus' => 'Main campus']);
    }

    public function test_students_cannot_sign_in_or_reuse_existing_sessions(): void
    {
        $student = $this->user('student');
        $student->password = Hash::make('Staff-only-2026!');
        $student->save();
        $this->post('/login', ['email' => $student->email, 'password' => 'Staff-only-2026!'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->actingAs($student)->get('/')->assertForbidden();
        foreach (['drive', 'documents', 'shared-with-me', 'history'] as $path) {
            $this->getJson('/workspace/'.$path)->assertForbidden();
        }
        $this->assertDatabaseHas('users', ['id' => $student->id]);
        $this->actingAs($this->user())->postJson('/accounts', ['name' => 'No student', 'email' => 'no@example.test', 'role' => 'student', 'password' => 'Staff-only-2026!', 'password_confirmation' => 'Staff-only-2026!'])->assertUnprocessable();
    }

    public function test_unscanned_infected_and_failed_versions_are_blocked_and_retry_can_release_only_clean_content(): void
    {
        Storage::fake('local');
        config(['dms.scanner' => null]);
        $this->actingAs($this->user());
        $file = $this->withHeader('Idempotency-Key', 'sdd-pending-upload')->post('/workspace/documents', [
            'title' => 'Pending evidence', 'subject' => 'Staff', 'reference' => 'EMP-1', 'source' => 'Employee Management',
            'category' => 'Qualification', 'classification' => 'Confidential',
            'file' => UploadedFile::fake()->createWithContent('evidence.txt', 'Synthetic file content.'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('scan', 'Pending')->json();
        $recipient = $this->user('employee');
        foreach (['Pending', 'Failed', 'Quarantined', 'Not required'] as $state) {
            DB::table('document_versions')->where('id', $file['version_id'])->update(['scan' => $state]);
            $this->getJson('/workspace/files/'.$file['version_id'])->assertForbidden();
            $this->getJson('/workspace/preview/'.$file['version_id'])->assertForbidden();
            $this->postJson('/workspace/drive/'.$file['document_id'].'/shares', ['email' => $recipient->email, 'permission' => 'viewer', 'expires_at' => now()->addDay()->toIso8601String()])->assertForbidden();
        }
        config(['dms.scanner' => 'test-clamscan']);
        $this->artisan('dms:scan')->assertSuccessful();
        $this->get('/workspace/files/'.$file['version_id'])->assertOk();
        $grant = $this->postJson('/workspace/drive/'.$file['document_id'].'/shares', ['email' => $recipient->email, 'expires_at' => now()->addDay()->toIso8601String()])->assertCreated()->json();
        DB::table('document_versions')->where('id', $file['version_id'])->update(['scan' => 'Quarantined']);
        $this->actingAs($recipient)->getJson($grant['url'])->assertForbidden();
        $this->assertDatabaseCount('document_versions', 1);
        $this->assertDatabaseHas('integration_events', ['type' => 'document.scan_completed']);
    }

    public function test_scanner_exit_codes_fail_closed(): void
    {
        foreach ([0 => 'Clean', 1 => 'Quarantined', 2 => 'Failed'] as $exit => $expected) {
            Process::fake(['*' => Process::result(exitCode: $exit)]);
            $this->assertSame($expected, app(FileScanner::class)->scan('private-path'));
        }
        config(['dms.scanner' => null]);
        $this->assertSame('Pending', app(FileScanner::class)->scan('private-path'));
        $this->artisan('dms:scan')->assertFailed();
    }

    public function test_connector_throttle_is_per_identity_even_at_the_same_ip(): void
    {
        foreach (['one', 'two'] as $token) {
            DB::table('integration_accounts')->insert(['name' => $token, 'source' => 'Employee Management', 'school_id' => 'DEMO-SCHOOL', 'campus' => 'Main campus', 'token_hash' => hash('sha256', $token), 'active' => true, 'created_at' => now()]);
        }
        for ($i = 0; $i < 120; $i++) {
            $this->withToken('one')->getJson('/api/v1/events')->assertOk();
        }
        $this->withToken('one')->getJson('/api/v1/events')->assertTooManyRequests();
        $this->withToken('two')->getJson('/api/v1/events')->assertOk();
    }
}
