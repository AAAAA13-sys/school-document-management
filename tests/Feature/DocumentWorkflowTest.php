<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'admin', string $school = 'DEMO-SCHOOL'): User
    {
        return User::factory()->create(['role' => $role, 'school_id' => $school, 'campus' => 'Main campus', 'active' => true]);
    }

    private function payload(array $extra = []): array
    {
        return array_merge(['title' => 'Test certificate', 'subject' => 'Test Student', 'reference' => 'APP-0001', 'source' => 'Online Admission', 'category' => 'Identity evidence', 'classification' => 'Restricted', 'file' => UploadedFile::fake()->createWithContent('record.txt', 'Synthetic record content for testing.')], $extra);
    }

    private function upload(array $extra = [], string $key = 'test-key-0001')
    {
        return $this->withHeader('Idempotency-Key', $key)->post('/workspace/documents', $this->payload($extra), ['Accept' => 'application/json']);
    }

    public function test_guests_cannot_read_documents(): void
    {
        $this->getJson('/workspace/documents')->assertUnauthorized();
        $this->getJson('/api/v1/documents')->assertUnauthorized();
    }

    public function test_upload_is_private_and_immediately_available(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account());
        $r = $this->upload()->assertCreated()->assertJsonPath('scan', 'Not required');
        $v = DB::table('document_versions')->first();
        Storage::disk('local')->assertExists($v->storage_key);
        $this->get('/workspace/files/'.$r['version_id'])->assertOk();
        $this->assertDatabaseCount('integration_events', 1);
    }

    public function test_idempotency_prevents_duplicate_records_and_rejects_changed_payload(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account());
        $a = $this->upload()->assertCreated();
        $b = $this->upload()->assertCreated();
        $this->assertSame($a['document_id'], $b['document_id']);
        $this->assertDatabaseCount('documents', 1);
        $this->upload(['title' => 'Changed title'])->assertConflict();
        $this->assertDatabaseCount('integration_events', 1);
    }

    public function test_school_and_role_boundaries_hide_records(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account());
        $id = $this->upload()->assertCreated()['document_id'];
        $this->actingAs($this->account('admin', 'OTHER'));
        $this->getJson('/workspace/documents/'.$id)->assertNotFound();
        $this->getJson('/workspace/documents')->assertJsonPath('total', 0);
        $this->actingAs($this->account('payroll'));
        $this->getJson('/workspace/documents/'.$id)->assertNotFound();
        $this->getJson('/workspace/stats')->assertJsonPath('total', 0);
    }

    public function test_hr_cannot_submit_payroll_files(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account('hr'));
        $this->upload(['source' => 'Payroll Management', 'category' => 'Payslip'])->assertForbidden();
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_unsafe_extensions_and_mismatched_content_are_rejected(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account());
        $this->upload(['file' => UploadedFile::fake()->createWithContent('record.php', '<?php echo 1;')])->assertUnprocessable();
        $this->upload(['file' => UploadedFile::fake()->createWithContent('record.pdf', 'This is plain text')])->assertUnprocessable();
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_clean_files_are_usable_without_business_review_and_review_endpoint_is_removed(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account());
        $r = $this->upload()->assertCreated();
        DB::table('document_versions')->update(['scan' => 'Clean', 'status' => 'Pending review']);
        $this->get('/workspace/files/'.$r['version_id'])->assertOk();
        $this->getJson('/workspace/documents?status=Available')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/workspace/documents/'.$r['document_id'].'/review', ['decision' => 'Approved'])->assertNotFound();
        $this->assertDatabaseHas('document_versions', ['id' => $r['version_id'], 'status' => 'Pending review']);
        $this->get('/')->assertOk()->assertDontSee('Review queue')->assertDontSee('Approve version');
    }

    public function test_new_version_preserves_previous_approval(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account());
        $r = $this->upload()->assertCreated();
        DB::table('document_versions')->update(['scan' => 'Clean', 'status' => 'Approved']);
        $next = $this->upload(['document_id' => $r['document_id'], 'revision' => 1], 'test-key-0002')->assertCreated()->assertJsonPath('version', 2);
        $this->assertDatabaseHas('document_versions', ['id' => $r['version_id'], 'status' => 'Approved']);
        $this->assertSame('Not required', $next['scan']);
        $this->upload(['document_id' => $r['document_id'], 'revision' => 1], 'test-key-0003')->assertConflict();
    }

    public function test_tampered_file_is_not_downloaded(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account());
        $r = $this->upload()->assertCreated();
        $v = DB::table('document_versions')->first();
        DB::table('document_versions')->update(['scan' => 'Clean']);
        Storage::disk('local')->put($v->storage_key, 'Tampered');
        $this->getJson('/workspace/files/'.$r['version_id'])->assertConflict();
    }

    public function test_connector_is_source_scoped_and_has_no_review_access(): void
    {
        Storage::fake('local');
        $this->actingAs($this->account());
        $r = $this->upload()->assertCreated();
        $this->app['auth']->forgetGuards();
        $token = Str::random(64);
        DB::table('integration_accounts')->insert(['name' => 'Payroll connector', 'school_id' => 'DEMO-SCHOOL', 'campus' => 'Main campus', 'source' => 'Payroll Management', 'token_hash' => hash('sha256', $token), 'active' => true, 'created_at' => now()]);
        $this->withToken($token)->getJson('/api/v1/documents')->assertJsonPath('total', 0);
        $this->withToken($token)->getJson('/api/v1/documents/'.$r['document_id'])->assertNotFound();
        $this->withToken($token)->getJson('/api/v1/events')->assertJsonPath('items', []);
        $this->withToken($token)->postJson('/api/v1/documents/'.$r['document_id'].'/review', ['decision' => 'Approved'])->assertNotFound();
    }

    public function test_nonadmin_cannot_read_audit_and_inactive_user_cannot_read_documents(): void
    {
        $u = $this->account('hr');
        $this->actingAs($u);
        $this->getJson('/workspace/audit')->assertForbidden();
        $u->active = false;
        $u->save();
        $this->getJson('/workspace/documents')->assertForbidden();
    }
}
