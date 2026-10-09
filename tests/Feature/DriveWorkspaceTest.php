<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DriveWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'school_id' => 'DEMO-SCHOOL', 'campus' => 'Main campus', 'active' => true]);
    }

    private function upload(): array
    {
        Storage::fake('local');

        return $this->withHeader('Idempotency-Key', 'drive-test-upload')->post('/workspace/documents', ['title' => 'Evidence', 'subject' => 'Synthetic person', 'reference' => 'APP-1', 'source' => 'Online Admission', 'category' => 'Identity evidence', 'classification' => 'Restricted', 'file' => UploadedFile::fake()->createWithContent('test.txt', 'Synthetic plain text evidence for safe preview.')], ['Accept' => 'application/json'])->assertCreated()->json();
    }

    public function test_files_can_be_organized_starred_trashed_and_restored_without_losing_versions(): void
    {
        $this->actingAs($this->user());
        $file = $this->upload();
        $id = $file['document_id'];
        $folder = $this->postJson('/workspace/folders', ['name' => 'Admissions', 'source' => 'Online Admission'])->assertCreated()['id'];
        $child = $this->postJson('/workspace/folders', ['name' => '2026', 'source' => 'Online Admission', 'parent_id' => $folder])->assertCreated()['id'];
        $this->postJson('/workspace/drive/'.$id.'/action', ['action' => 'move', 'folder_id' => $child, 'revision' => 1])->assertOk();
        $this->getJson('/workspace/drive?folder='.$child)->assertOk()->assertJsonCount(1, 'files.data')->assertJsonCount(2, 'breadcrumbs');
        $this->getJson('/workspace/drive?location=home')->assertOk()->assertJsonCount(1, 'files.data')->assertJsonCount(1, 'folders');
        $this->postJson('/workspace/drive/'.$id.'/action', ['action' => 'rename', 'title' => 'Renamed', 'revision' => 1])->assertConflict();
        $this->postJson('/workspace/drive/'.$id.'/action', ['action' => 'rename', 'title' => 'Renamed', 'revision' => 2])->assertOk();
        $this->postJson('/workspace/drive/'.$id.'/action', ['action' => 'star', 'revision' => 3])->assertOk();
        $this->getJson('/workspace/drive?location=starred')->assertOk()->assertJsonPath('files.data.0.title', 'Renamed');
        $this->postJson('/workspace/drive/'.$id.'/action', ['action' => 'trash', 'revision' => 3])->assertOk();
        $this->getJson('/workspace/documents/'.$id)->assertNotFound();
        $this->getJson('/workspace/drive?location=trash')->assertOk()->assertJsonCount(1, 'files.data');
        $this->getJson('/workspace/stats')->assertJsonPath('total', 0);
        $this->postJson('/workspace/drive/'.$id.'/action', ['action' => 'restore', 'revision' => 4])->assertOk();
        $this->getJson('/workspace/documents/'.$id)->assertOk();
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_folder_and_file_actions_enforce_source_boundaries(): void
    {
        $this->actingAs($this->user());
        $file = $this->upload();
        $folder = $this->postJson('/workspace/folders', ['name' => 'Payroll', 'source' => 'Payroll Management'])->assertCreated()['id'];
        $this->postJson('/workspace/drive/'.$file['document_id'].'/action', ['action' => 'move', 'folder_id' => $folder, 'revision' => 1])->assertUnprocessable();
        $this->postJson('/workspace/folders', ['name' => 'Wrong source', 'source' => 'Online Admission', 'parent_id' => $folder])->assertUnprocessable();
        $this->actingAs($this->user('payroll'));
        $this->postJson('/workspace/drive/'.$file['document_id'].'/action', ['action' => 'trash', 'revision' => 1])->assertNotFound();
        $this->getJson('/workspace/drive')->assertJsonCount(0, 'files.data');
    }

    public function test_preview_is_scan_gated_and_integrity_checked(): void
    {
        $this->actingAs($this->user());
        $file = $this->upload();
        $url = '/workspace/preview/'.$file['version_id'];
        $this->getJson($url)->assertForbidden();
        DB::table('document_versions')->where('id', $file['version_id'])->update(['scan' => 'Clean']);
        $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; frame-ancestors 'self'");
        $v = DB::table('document_versions')->first();
        Storage::disk('local')->put($v->storage_key, 'tampered');
        $this->getJson($url)->assertConflict();
    }

    public function test_named_shares_pin_versions_and_enforce_recipient_expiry_revocation(): void
    {
        $owner = $this->user();
        $recipient = $this->user('registrar');
        $wrong = $this->user('payroll');
        $this->actingAs($owner);
        $file = $this->upload();
        $id = $file['document_id'];
        DB::table('document_versions')->where('id', $file['version_id'])->update(['scan' => 'Clean']);
        $wrong->forceFill(['school_id' => 'OTHER-SCHOOL'])->save();
        $this->postJson('/workspace/drive/'.$id.'/shares', ['email' => $wrong->email, 'expires_at' => now()->addDay()->toIso8601String()])->assertForbidden();
        $grant = $this->postJson('/workspace/drive/'.$id.'/shares', ['email' => $recipient->email, 'expires_at' => now()->addDay()->toIso8601String()])->assertCreated()->json();
        $this->actingAs($wrong);
        $this->getJson($grant['url'])->assertNotFound();
        $this->actingAs($recipient);
        $this->get($grant['url'])->assertOk();
        $this->travel(2)->days();
        $this->getJson($grant['url'])->assertNotFound();
        $this->travelBack();
        $this->actingAs($owner);
        $this->deleteJson('/workspace/shares/'.$grant['id'])->assertOk();
        $this->actingAs($recipient);
        $this->getJson($grant['url'])->assertNotFound();
    }
}
