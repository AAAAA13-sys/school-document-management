<?php

namespace Tests\Feature;

use App\Enums\Source;
use App\Jobs\ScanDocumentVersion;
use App\Models\User;
use App\Services\InvitationService;
use App\Services\Scanner;
use App\Services\SensitiveChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DmsTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'synthetic-connector-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('testing', app()->environment());
        $this->assertTrue(config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === ':memory:' || config('database.connections.mysql.database') === 'dms_test');
        Storage::fake('local');
        Queue::fake();
        DB::table('connectors')->insert(['name' => 'Test EMS', 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main', 'token_hash' => hash('sha256', $this->token), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function staff(array $attributes = []): User
    {
        return User::factory()->create($attributes + ['role' => 'admin', 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main', 'active' => true]);
    }

    private function event(array $overrides = []): array
    {
        return $overrides + ['event_id' => 'event-1', 'record_type' => 'employee', 'record_id' => 'EMP-001', 'revision' => 1, 'operation' => 'upsert', 'payload' => ['name' => 'Synthetic staff', 'department' => 'Faculty']];
    }

    private function api(array $event)
    {
        return $this->withToken($this->token)->postJson('/api/v1/history', $event);
    }

    private function file(string $name = 'sample.txt', string $content = 'Synthetic document contents'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    private function upload(User $user, array $overrides = []): string
    {
        $this->mock(Scanner::class, fn ($m) => $m->shouldReceive('scan')->andReturn('Clean'));
        $response = $this->actingAs($user)->post('/documents', $overrides + ['title' => 'Synthetic staff file', 'source' => 'EMS', 'classification' => 'Internal', 'submission_key' => fake()->uuid(), 'file' => $this->file()]);
        $response->assertRedirect();
        $id = DB::table('documents')->latest('created_at')->value('id');
        $version = DB::table('document_versions')->where('document_id', $id)->first();
        (new ScanDocumentVersion($version->id))->handle(app(Scanner::class));

        return $id;
    }

    private function acceptInvitation(string $email): User
    {
        $row = DB::table('account_invitations')->where('email', $email)->first();
        $this->assertNotNull($row);
        if ($row->status === 'pending') {
            $reviewer = $this->staff(['role' => 'super_admin']);
            app(InvitationService::class)->review($reviewer, $row->id, true, 'Approved invitation fixture.');
        }
        $token = str_repeat('x', 64);
        DB::table('account_invitations')->where('id', $row->id)->update(['status' => 'sent', 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);

        return app(InvitationService::class)->accept($token, 'SyntheticInvite!2026');
    }

    private function seedHistoricalFolderGrant(string $folder, array $data): void
    {
        DB::table('folder_shares')->updateOrInsert(['folder_id' => $folder, 'recipient_id' => $data['recipient_id']],
            $data + ['revoked_at' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function documentRevision(string $id): int
    {
        return (int) DB::table('documents')->where('id', $id)->value('revision');
    }

    private function approvePending(): void
    {
        foreach (DB::table('sensitive_changes')->where('status', 'pending')->get() as $change) {
            $requester = User::findOrFail($change->requester_id);
            $this->assertSame((int) $change->revision, $this->documentRevision($change->document_id));
            $reviewer = $this->staff(['role' => $requester->isAdmin() ? 'super_admin' : 'admin', 'source' => $requester->source]);
            app(SensitiveChangeService::class)->decide($reviewer, $change->id, true, 'Approved fixture change for existing end-to-end checks.');
        }
    }

    public function test_history_replays_are_idempotent_and_reject_changed_content(): void
    {
        $first = $this->api($this->event())->assertCreated()->assertJsonPath('duplicate', false)->json('history_id');
        $this->api($this->event())->assertOk()->assertJsonPath('history_id', $first)->assertJsonPath('duplicate', true);
        $this->api($this->event(['payload' => ['name' => 'Changed']]))->assertConflict();
        $this->assertDatabaseCount('history_receipts', 1);
    }

    public function test_canonical_hash_ignores_object_key_order(): void
    {
        $this->api($this->event())->assertCreated();
        $this->api($this->event(['payload' => ['department' => 'Faculty', 'name' => 'Synthetic staff']]))->assertOk();
    }

    public function test_old_revision_is_preserved_and_same_revision_conflicts(): void
    {
        $this->api($this->event(['event_id' => 'new', 'revision' => 3]))->assertCreated()->assertJsonPath('applied', true);
        $this->api($this->event())->assertCreated()->assertJsonPath('applied', false);
        $this->api($this->event(['event_id' => 'collision']))->assertConflict();
        $this->assertDatabaseHas('source_snapshots', ['revision' => 3]);
        $this->assertDatabaseCount('history_receipts', 2);
    }

    public function test_delete_creates_a_tombstone_without_erasing_history(): void
    {
        $this->api($this->event())->assertCreated();
        $this->api($this->event(['event_id' => 'deleted', 'revision' => 2, 'operation' => 'delete', 'payload' => []]))->assertCreated();
        $this->assertDatabaseHas('source_snapshots', ['deleted' => true, 'revision' => 2]);
        $this->assertDatabaseCount('history_receipts', 2);
        $this->withToken($this->token)->getJson('/api/v1/snapshots/employee/EMP-001')->assertOk()->assertJsonPath('data.deleted', 1);
    }

    public function test_history_validation_and_payload_limit(): void
    {
        $this->api($this->event(['revision' => 0]))->assertUnprocessable();
        $this->api($this->event(['school_id' => 'OTHER']))->assertUnprocessable();
        $this->api($this->event(['payload' => ['large' => str_repeat('x', 262145)]]))->assertUnprocessable();
    }

    public function test_connector_authentication_revocation_scope_and_throttle(): void
    {
        $this->getJson('/api/v1/history')->assertUnauthorized();
        $this->withToken('invalid')->getJson('/api/v1/history')->assertUnauthorized();
        $this->api($this->event())->assertCreated();
        DB::table('connectors')->insert(['name' => 'Other', 'source' => 'PMS', 'school_id' => 'PLP', 'campus' => 'Main', 'token_hash' => hash('sha256', 'other'), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken('other')->getJson('/api/v1/history')->assertOk()->assertJsonCount(0, 'data');
        DB::table('connectors')->where('token_hash', hash('sha256', 'other'))->update(['active' => false]);
        $this->withToken('other')->getJson('/api/v1/events')->assertUnauthorized();
    }

    public function test_readback_cursor_and_validation(): void
    {
        $this->api($this->event())->assertCreated();
        $this->api($this->event(['event_id' => 'event-2', 'revision' => 2]))->assertCreated();
        $first = $this->withToken($this->token)->getJson('/api/v1/history?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('has_more', true)->json('next_cursor');
        $this->withToken($this->token)->getJson('/api/v1/history?after='.$first)->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.request_hash');
        $this->withToken($this->token)->getJson('/api/v1/history?limit=1000')->assertUnprocessable();
    }

    public function test_all_five_workspaces_and_internal_pages_render(): void
    {
        $admin = $this->staff(['role' => 'super_admin']);
        foreach (['ems', 'pms', 'es', 'oas'] as $source) {
            foreach (['overview', 'documents', 'history', 'snapshots'] as $section) {
                $this->actingAs($admin)->get('/workspaces/'.$source.'/'.$section)->assertOk()->assertSee('PLP Documents');
            }
        }
        foreach (['overview', 'repository', 'accounts', 'connectors', 'scans', 'audit'] as $section) {
            $this->actingAs($admin)->get('/admin/'.$section)->assertOk();
        }
    }

    public function test_roles_and_inactive_sessions_are_enforced(): void
    {
        $user = $this->staff(['role' => 'staff']);
        $this->actingAs($user)->get('/admin')->assertForbidden();
        $this->get('/workspaces/pms')->assertForbidden();
        $this->get('/workspaces/ems/history')->assertForbidden();
        $user->update(['active' => false]);
        $this->get('/workspaces/ems')->assertRedirect('/login');
    }

    public function test_sign_in_and_sign_out(): void
    {
        $user = $this->staff(['password' => 'SyntheticPass!123']);
        $this->get('/login')->assertOk();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors();
        $this->post('/login', ['email' => $user->email, 'password' => 'SyntheticPass!123'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_upload_versioning_retries_and_checksum_enforcement(): void
    {
        $user = $this->staff();
        $id = $this->upload($user, ['submission_key' => 'same']);
        $this->post('/documents', ['title' => 'Synthetic staff file', 'source' => 'EMS', 'classification' => 'Internal', 'submission_key' => 'same', 'file' => $this->file()])->assertRedirect('/documents/'.$id);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_versions', 1);
        $this->post('/documents', ['title' => 'Changed', 'source' => 'EMS', 'classification' => 'Internal', 'submission_key' => 'same', 'file' => $this->file()])->assertConflict();
        $this->post('/documents/'.$id.'/versions', ['source' => 'EMS', 'revision' => $this->documentRevision($id), 'submission_key' => 'version-2', 'file' => $this->file('next.txt', 'New contents')])->assertRedirect();
        $this->assertDatabaseCount('document_versions', 2);
        $this->assertDatabaseHas('documents', ['id' => $id, 'revision' => 2]);
        $this->post('/documents/'.$id.'/versions', ['source' => 'EMS', 'revision' => 1, 'submission_key' => 'stale', 'file' => $this->file()])->assertConflict();
        $v = DB::table('document_versions')->where('document_id', $id)->where('number', 1)->first();
        $this->get('/files/'.$v->id)->assertOk()->assertDownload('sample.txt');
        Storage::disk('local')->put($v->storage_key, 'tampered');
        $this->get('/files/'.$v->id)->assertConflict();
    }

    public function test_failed_scans_block_access_and_retry_command_is_fail_closed(): void
    {
        $user = $this->staff();
        $id = $this->upload($user);
        $v = DB::table('document_versions')->where('document_id', $id)->first();
        DB::table('document_versions')->where('id', $v->id)->update(['scan_status' => 'Failed']);
        $this->get('/files/'.$v->id)->assertStatus(423);
        $this->artisan('dms:scan-retry')->assertExitCode(0);
        Queue::assertPushed(ScanDocumentVersion::class);
        (new ScanDocumentVersion($v->id))->handle(app(Scanner::class));
        $this->assertDatabaseHas('document_versions', ['id' => $v->id, 'scan_status' => 'Clean']);
        DB::table('document_versions')->where('id', $v->id)->update(['scan_status' => 'Quarantined']);
        $this->artisan('dms:scan-retry')->assertExitCode(0);
        $this->get('/files/'.$v->id)->assertStatus(423);
    }

    public function test_scanner_unavailable_is_failed_never_clean(): void
    {
        config(['dms.clamav_binary' => 'nonexistent-dms-clamscan']);
        Storage::disk('local')->put('test-file', 'synthetic');
        $this->assertSame('Failed', (new Scanner)->scan(Storage::disk('local')->path('test-file')));
    }

    public function test_upload_accepts_any_format_but_rejects_oversize_files(): void
    {
        $user = $this->staff();
        $base = ['title' => 'Not accepted', 'source' => 'EMS', 'classification' => 'Internal', 'submission_key' => 'bad'];
        $this->actingAs($user)->post('/documents', $base + ['file' => $this->file('source.php', '<?php // Harmless source-code fixture')])->assertRedirect()->assertSessionHasNoErrors();
        $version = DB::table('document_versions')->first();
        $this->get('/files/'.$version->id)->assertStatus(423);
        $this->mock(Scanner::class, fn ($m) => $m->shouldReceive('scan')->andReturn('Clean'));
        (new ScanDocumentVersion($version->id))->handle(app(Scanner::class));
        $download = $this->get('/files/'.$version->id)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('attachment', $download->headers->get('Content-Disposition'));
        $this->post('/documents', array_replace($base, ['submission_key' => 'oversized', 'file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')]))->assertSessionHasErrors('file');
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_school_and_campus_isolation_even_for_admin(): void
    {
        $owner = $this->staff();
        $id = $this->upload($owner);
        $v = DB::table('document_versions')->first();
        foreach ([['school_id' => 'OTHER'], ['campus' => 'Other']] as $scope) {
            $outsider = $this->staff($scope + ['role' => 'super_admin']);
            $this->actingAs($outsider)->get('/documents/'.$id)->assertNotFound();
            $this->get('/files/'.$v->id)->assertNotFound();
            $this->post('/documents/'.$id.'/shares', ['recipient_id' => $owner->id, 'permission' => 'Editor'])->assertNotFound();
        }
    }

    public function test_editor_can_rename_and_version_but_cannot_move_trash_or_reshare(): void
    {
        $owner = $this->staff();
        $id = $this->upload($owner);
        $editor = $this->staff(['role' => 'staff']);
        $recipient = $this->staff();
        $this->actingAs($owner)->post('/documents/'.$id.'/shares', ['revision' => $this->documentRevision($id), 'recipient_id' => $editor->id, 'permission' => 'Editor'])->assertRedirect();
        $this->approvePending();
        $this->actingAs($editor)->patch('/documents/'.$id, ['title' => 'Renamed', 'revision' => $this->documentRevision($id), 'folder_id' => null])->assertRedirect();
        $this->post('/documents/'.$id.'/trash', ['revision' => $this->documentRevision($id)])->assertForbidden();
        $this->post('/documents/'.$id.'/shares', ['recipient_id' => $recipient->id, 'permission' => 'Viewer'])->assertForbidden();
        $this->post('/documents/'.$id.'/versions', ['source' => 'EMS', 'revision' => $this->documentRevision($id), 'submission_key' => 'editor-v2', 'file' => $this->file()])->assertRedirect();
        $this->assertDatabaseCount('document_versions', 2);
        $this->post('/documents/'.$id.'/comments', ['body' => 'A valid comment'])->assertRedirect();
    }

    public function test_viewer_comments_expiry_revocation_and_pinned_versions(): void
    {
        $owner = $this->staff();
        $id = $this->upload($owner);
        $viewer = $this->staff(['role' => 'staff', 'source' => 'PMS']);
        $v = DB::table('document_versions')->first();
        $this->post('/documents/'.$id.'/shares', ['revision' => $this->documentRevision($id), 'recipient_id' => $viewer->id, 'permission' => 'Viewer', 'version_id' => $v->id])->assertRedirect();
        $this->approvePending();
        $this->post('/documents/'.$id.'/versions', ['source' => 'EMS', 'revision' => $this->documentRevision($id), 'submission_key' => 'new', 'file' => $this->file('next.txt')])->assertRedirect();
        $latest = DB::table('document_versions')->where('number', 2)->first();
        $this->actingAs($viewer)->get('/files/'.$v->id)->assertOk();
        $this->get('/files/'.$latest->id)->assertForbidden();
        $this->post('/documents/'.$id.'/comments', ['body' => 'Not allowed'])->assertForbidden();
        $share = DB::table('shares')->first();
        $this->actingAs($owner)->delete('/documents/'.$id.'/shares/'.$share->id)->assertRedirect();
        // Employees may still read their own documents, but a revoked recipient loses this file.
        $other = $this->staff(['role' => 'staff', 'source' => 'OAS']);
        $this->post('/documents/'.$id.'/shares', ['revision' => $this->documentRevision($id), 'recipient_id' => $other->id, 'permission' => 'Commenter', 'expires_at' => now()->addHour()->toDateTimeString()])->assertRedirect();
        $this->approvePending();
        $this->actingAs($other)->get('/documents/'.$id)->assertOk();
        $this->get('/workspaces/oas/documents?view=shared')->assertSee('Synthetic staff file');
        $this->post('/documents/'.$id.'/comments', ['body' => str_repeat('x', 2001)])->assertSessionHasErrors('body');
        DB::table('shares')->where('recipient_id', $other->id)->update(['expires_at' => now()->subMinute()]);
        $this->get('/documents/'.$id)->assertNotFound();
    }

    public function test_trash_restore_revision_safety_and_audit(): void
    {
        $user = $this->staff();
        $id = $this->upload($user);
        $v = DB::table('document_versions')->first();
        $this->post('/documents/'.$id.'/trash', ['revision' => 99])->assertConflict();
        $this->post('/documents/'.$id.'/trash', ['revision' => $this->documentRevision($id)])->assertRedirect();
        $this->approvePending();
        $this->get('/files/'.$v->id)->assertNotFound();
        $this->post('/documents/'.$id.'/restore', ['revision' => $this->documentRevision($id)])->assertRedirect();
        $this->approvePending();
        $this->get('/files/'.$v->id)->assertOk();
        $this->assertDatabaseCount('document_versions', 1);
        $this->assertDatabaseHas('audit_entries', ['action' => 'trash.applied', 'document_id' => $id]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'restore.applied', 'document_id' => $id]);
        $this->get('/documents/'.$id)->assertOk();
    }

    public function test_nested_folders_and_cycle_protection(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->post('/folders', ['name' => 'Parent', 'source' => 'EMS'])->assertRedirect();
        $parent = DB::table('folders')->value('id');
        $this->post('/folders', ['name' => 'Child', 'source' => 'EMS', 'parent_id' => $parent])->assertRedirect();
        $child = DB::table('folders')->where('name', 'Child')->value('id');
        $this->patch('/folders/'.$parent, ['name' => 'Bad move', 'revision' => 1, 'parent_id' => $child])->assertUnprocessable();
        $this->get('/workspaces/ems/documents?folder='.$parent)->assertOk()->assertSee('Child');
        $other = $this->staff(['source' => 'PMS']);
        $this->actingAs($other)->post('/folders', ['name' => 'Outside', 'source' => 'EMS', 'parent_id' => $parent])->assertForbidden();
    }

    public function test_accounts_inherit_scope_and_self_lockout_is_prevented(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $admin = $this->staff(['role' => 'super_admin']);
        $data = ['name' => 'Synthetic colleague', 'email' => 'colleague@example.test', 'role' => 'admin', 'source' => 'EMS', 'active' => 1, 'school_id' => 'FORGED'];
        $this->actingAs($admin)->post('/admin/accounts', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        $this->acceptInvitation($data['email']);
        $this->assertDatabaseHas('users', ['email' => 'colleague@example.test', 'school_id' => 'PLP', 'campus' => 'Main']);
        $this->patch('/admin/accounts/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'password' => '', 'role' => 'staff', 'source' => 'EMS', 'active' => 0])->assertUnprocessable();
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'role' => 'super_admin', 'active' => true]);
        $data['email'] = 'weak@example.test';
        $data['password'] = 'weak';
        $this->post('/admin/accounts', $data)->assertSessionHasErrors('password');
    }

    public function test_connector_tokens_are_hashed_and_administration_is_restricted(): void
    {
        $admin = $this->staff(['role' => 'super_admin']);
        $this->actingAs($admin)->post('/admin/connectors', ['name' => 'Synthetic PMS', 'source' => 'PMS'])->assertRedirect()->assertSessionHas('connector_token');
        $token = session('connector_token');
        $this->assertDatabaseHas('connectors', ['name' => 'Synthetic PMS', 'token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('connectors', ['token_hash' => $token]);
        $id = DB::table('connectors')->where('name', 'Synthetic PMS')->value('id');
        $this->delete('/admin/connectors/'.$id)->assertRedirect();
        $this->withToken($token)->getJson('/api/v1/events')->assertUnauthorized();
        $employee = $this->staff(['role' => 'staff']);
        $this->actingAs($employee)->post('/admin/connectors', ['name' => 'Unauthorized', 'source' => 'EMS'])->assertForbidden();
    }

    public function test_api_document_upload_is_scoped_idempotent_and_readable(): void
    {
        $this->mock(Scanner::class, fn ($m) => $m->shouldReceive('scan')->andReturn('Clean'));
        $base = ['title' => 'ERP evidence', 'classification' => 'Confidential', 'file' => $this->file()];
        $result = $this->withToken($this->token)->withHeader('Idempotency-Key', 'erp-doc')->postJson('/api/v1/documents', $base)->assertCreated()->json();
        $this->withToken($this->token)->withHeader('Idempotency-Key', 'erp-doc')->postJson('/api/v1/documents', ['title' => 'ERP evidence', 'classification' => 'Confidential', 'file' => $this->file()])->assertOk();
        $this->assertDatabaseCount('document_versions', 1);
        $this->getJson('/api/v1/documents/'.$result['document_id'])->assertOk()->assertJsonMissingPath('versions.0.storage_key');
        $this->getJson('/api/v1/documents')->assertOk()->assertJsonCount(1, 'data');
        $this->get('/api/v1/files/'.$result['version_id'])->assertStatus(423);
        (new ScanDocumentVersion($result['version_id']))->handle(app(Scanner::class));
        $this->get('/api/v1/files/'.$result['version_id'])->assertOk();
    }

    public function test_upload_returns_pending_and_queues_once_without_running_scanner(): void
    {
        $user = $this->staff();
        $this->mock(Scanner::class, fn ($m) => $m->shouldNotReceive('scan'));
        $input = ['title' => 'Async upload', 'source' => 'EMS', 'classification' => 'Internal', 'submission_key' => 'async'];
        $result = $this->actingAs($user)->postJson('/documents', $input + ['file' => $this->file()])->assertCreated()->assertJsonPath('scan_status', 'Pending')->json();
        $this->postJson('/documents', $input + ['file' => $this->file()])->assertOk();
        Queue::assertPushed(ScanDocumentVersion::class, 1);
        $this->get('/files/'.$result['version_id'])->assertStatus(423);
        $this->getJson('/documents/'.$result['document_id'].'/scan-status')->assertOk()->assertJsonPath('versions.0.scan_status', 'Pending');
    }

    public function test_worker_failure_tampering_and_quarantine_never_unlock_files(): void
    {
        $user = $this->staff();
        $id = $this->upload($user);
        $version = DB::table('document_versions')->first();
        foreach (['Failed', 'Quarantined'] as $outcome) {
            DB::table('document_versions')->where('id', $version->id)->update(['scan_status' => 'Pending']);
            $scanner = \Mockery::mock(Scanner::class);
            $scanner->shouldReceive('scan')->once()->andReturn($outcome);
            try {
                (new ScanDocumentVersion($version->id))->handle($scanner);
            } catch (\RuntimeException $e) {
                $this->assertSame('Failed', $outcome);
            }
            $this->get('/files/'.$version->id)->assertStatus(423);
            $this->assertDatabaseHas('document_versions', ['id' => $version->id, 'scan_status' => $outcome]);
        }
        $scanner = \Mockery::mock(Scanner::class);
        $scanner->shouldNotReceive('scan');
        (new ScanDocumentVersion($version->id))->handle($scanner);
        DB::table('document_versions')->where('id', $version->id)->update(['scan_status' => 'Pending']);
        Storage::disk('local')->put($version->storage_key, 'changed bytes');
        try {
            (new ScanDocumentVersion($version->id))->handle($scanner);
            $this->fail('Tampered file passed');
        } catch (\RuntimeException $e) {
            $this->assertDatabaseHas('document_versions', ['id' => $version->id, 'scan_status' => 'Failed']);
        }
    }

    public function test_profile_password_requires_current_password_and_revokes_old_sessions(): void
    {
        $user = $this->staff(['password' => 'SyntheticPass!123']);
        $this->actingAs($user)->get('/account')->assertOk()->assertSee('Change password');
        $this->put('/account/password', ['current_password' => 'wrong', 'password' => 'NewSynthetic!123', 'password_confirmation' => 'NewSynthetic!123'])->assertSessionHasErrors('current_password');
        $this->put('/account/password', ['current_password' => 'SyntheticPass!123', 'password' => 'NewSynthetic!123', 'password_confirmation' => 'NewSynthetic!123'])->assertRedirect();
        $this->assertTrue(Hash::check('NewSynthetic!123', $user->fresh()->password));
        $this->withSession(['auth_version' => 1])->get('/account')->assertRedirect('/login');
        $this->assertDatabaseHas('audit_entries', ['actor_id' => $user->id, 'action' => 'account.password_changed']);
    }

    public function test_all_four_erp_tokens_are_isolated_with_case_sensitive_identities(): void
    {
        foreach (['EMS', 'PMS', 'ES', 'OAS'] as $source) {
            $token = 'test-'.$source;
            DB::table('connectors')->insert(['name' => $source, 'source' => $source, 'school_id' => 'PLP', 'campus' => 'Main', 'token_hash' => hash('sha256', $token), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            $this->withToken($token)->postJson('/api/v1/history', $this->event())->assertCreated();
            $this->withToken($token)->getJson('/api/v1/history')->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', $source);
            $this->withToken($token)->postJson('/api/v1/history', $this->event(['event_id' => 'EVENT-1', 'revision' => 2]))->assertCreated();
        }
        $this->assertDatabaseCount('history_receipts', 8);
    }

    public function test_history_preserves_json_objects_empty_strings_and_whitespace(): void
    {
        $event = $this->event(['payload' => (object) ['empty' => (object) [], 'list' => [], 'text' => '  keep me  ', 'blank' => '']]);
        $this->withToken($this->token)->postJson('/api/v1/history', $event)->assertCreated();
        $stored = json_decode(DB::table('history_receipts')->value('payload'));
        $this->assertInstanceOf(\stdClass::class, $stored->empty);
        $this->assertSame([], $stored->list);
        $this->assertSame('  keep me  ', $stored->text);
        $this->assertSame('', $stored->blank);
        $event['payload']->empty = [];
        $this->withToken($this->token)->postJson('/api/v1/history', $event)->assertConflict();
    }

    public function test_no_repository_scope_notice_and_no_registration(): void
    {
        $this->actingAs($this->staff(['role' => 'super_admin']))->get('/admin')->assertDontSee('Private school repository')->assertDontSee('PLP · Main campus');
        $this->get('/register')->assertNotFound();
    }

    public function test_folder_batch_preserves_nested_paths_and_replays_without_duplicate_files(): void
    {
        $user = $this->staff();
        $data = ['source' => 'EMS', 'classification' => 'Internal', 'submission_key' => 'folder-batch', 'paths' => ['Office/one.txt', 'Office/2026/two.txt']];
        $files = fn () => ['files' => [$this->file('one.txt', 'Office one contents'), $this->file('two.txt', 'Office two contents')]];
        $this->actingAs($user)->postJson('/documents/batch', $data + $files())->assertCreated()->assertJsonPath('results.0.scan_status', 'Pending');
        $this->postJson('/documents/batch', $data + $files())->assertCreated()->assertJsonPath('results.0.status', 200);
        $this->assertDatabaseCount('documents', 2);
        $this->assertDatabaseCount('folders', 2);
        $this->assertDatabaseCount('document_versions', 2);
        $root = DB::table('folders')->where('name', 'Office')->value('id');
        $this->assertDatabaseHas('folders', ['name' => '2026', 'parent_id' => $root]);
        $data['paths'][0] = '../one.txt';
        $this->postJson('/documents/batch', $data + $files())->assertUnprocessable();
        $this->postJson('/documents/batch', array_replace($data, ['files' => array_fill(0, 51, $this->file()), 'paths' => array_fill(0, 51, 'Office/sample.txt')]))->assertUnprocessable();
    }

    public function test_regrant_removes_old_pin_and_uses_capped_expiry(): void
    {
        $owner = $this->staff();
        $id = $this->upload($owner);
        $recipient = $this->staff(['role' => 'staff']);
        $v = DB::table('document_versions')->first();
        $this->post('/documents/'.$id.'/shares', ['revision' => $this->documentRevision($id), 'recipient_id' => $recipient->id, 'permission' => 'Viewer', 'version_id' => $v->id, 'expires_at' => now()->addDay()->toDateTimeString()])->assertRedirect();
        $this->approvePending();
        $this->post('/documents/'.$id.'/shares', ['revision' => $this->documentRevision($id), 'recipient_id' => $recipient->id, 'permission' => 'Editor'])->assertRedirect();
        $this->approvePending();
        $this->assertDatabaseHas('shares', ['document_id' => $id, 'recipient_id' => $recipient->id, 'permission' => 'Editor', 'version_id' => null]);
        $this->actingAs($recipient)->patch('/documents/'.$id, ['title' => 'Editor regrant works', 'revision' => $this->documentRevision($id)])->assertRedirect();
    }

    public function test_preview_is_opt_in_and_carries_sandbox_headers(): void
    {
        $id = $this->upload($this->staff());
        $version = DB::table('document_versions')->first();
        config(['dms.preview_mimes' => []]);
        $this->get('/files/'.$version->id.'/preview')->assertStatus(415);
        config(['dms.preview_mimes' => ['text/plain']]);
        $this->get('/files/'.$version->id.'/preview')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'");
    }

    public function test_login_normalizes_email_and_throttles_repeated_failures(): void
    {
        $user = $this->staff(['email' => 'normalize@example.test', 'password' => 'SyntheticPass!123']);
        $this->post('/login', ['email' => 'NORMALIZE@EXAMPLE.TEST', 'password' => 'SyntheticPass!123'])->assertRedirect();
        $this->post('/logout');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertRedirect();
        }
        $this->post('/login', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_scan_status_changes_preserve_original_upload_timestamp(): void
    {
        $id = $this->upload($this->staff());
        $v = DB::table('document_versions')->first();
        DB::table('document_versions')->where('id', $v->id)->update(['created_at' => '2020-01-02 03:04:05', 'scan_status' => 'Pending']);
        (new ScanDocumentVersion($v->id))->handle(app(Scanner::class));
        $this->assertDatabaseHas('document_versions', ['id' => $v->id, 'created_at' => '2020-01-02 03:04:05', 'scan_status' => 'Clean']);
    }

    public function test_folder_cards_count_visible_active_documents(): void
    {
        $user = $this->staff();
        $this->actingAs($user)->post('/folders', ['name' => 'Counted folder', 'source' => 'EMS'])->assertRedirect();
        $folder = DB::table('folders')->value('id');
        $id = $this->upload($user, ['folder_id' => $folder]);
        $this->get('/workspaces/ems/documents')->assertViewHas('folders', fn ($folders) => (int) ($folders->firstWhere('id', $folder)->document_count ?? 0) === 1);
        $this->post('/documents/'.$id.'/trash', ['revision' => $this->documentRevision($id)])->assertRedirect();
        $this->approvePending();
        $this->get('/workspaces/ems/documents')->assertViewHas('folders', fn ($folders) => (int) ($folders->firstWhere('id', $folder)->document_count ?? 0) === 0);
    }

    public function test_super_admin_account_is_source_independent_and_dms_source_is_rejected(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::fake();
        $admin = $this->staff(['role' => 'super_admin']);
        $data = ['name' => 'New Admin', 'email' => 'new.admin@example.test', 'role' => 'super_admin', 'source' => 'EMS', 'active' => 1];
        $this->actingAs($admin)->post('/admin/accounts', $data)->assertRedirect()->assertSessionHasNoErrors();
        $created = $this->acceptInvitation($data['email']);
        $this->assertNull($created->source);
        $this->assertEqualsCanonicalizing(['EMS', 'PMS', 'ES', 'OAS'], $created->sources());
        $this->actingAs($created)->get('/account')->assertOk()->assertSee('DMS ADMINISTRATION')->assertSee('All registered workspaces')->assertSee('account-grid', false);
        foreach (['ems', 'pms', 'es', 'oas'] as $source) {
            $this->get('/workspaces/'.$source)->assertOk();
        }
        $data['email'] = 'employee@example.test';
        $data['role'] = 'staff';
        $data['source'] = 'DMS';
        $this->post('/admin/accounts', $data)->assertSessionHasErrors('source');
    }

    public function test_inactive_connector_displays_not_connected(): void
    {
        DB::table('connectors')->update(['active' => false]);
        $this->actingAs($this->staff(['role' => 'super_admin', 'source' => 'DMS']))->get('/admin/connectors')->assertOk()->assertSee('Not connected')->assertDontSee('Revoked');
    }

    public function test_super_admin_can_manage_documents_and_folders_in_school_scope(): void
    {
        $owner = $this->staff();
        $id = $this->upload($owner);
        $super = $this->staff(['role' => 'super_admin']);
        $this->actingAs($super)->get('/documents/'.$id)->assertOk()->assertSee('Upload new version')->assertSee('Manage access');
        $this->patch('/documents/'.$id, ['title' => 'Super updated', 'revision' => 1])->assertRedirect();
        $this->post('/documents/'.$id.'/trash', ['revision' => $this->documentRevision($id)])->assertRedirect();
        $this->assertNotNull(DB::table('documents')->where('id', $id)->value('trashed_at'));
        $this->post('/documents/'.$id.'/restore', ['revision' => $this->documentRevision($id)])->assertRedirect();
        $this->assertNull(DB::table('documents')->where('id', $id)->value('trashed_at'));
        $this->post('/folders', ['source' => 'EMS', 'name' => 'Super folder'])->assertRedirect();
        $this->assertDatabaseHas('folders', ['name' => 'Super folder', 'owner_id' => $super->id]);
        foreach (['ems', 'pms', 'es', 'oas'] as $source) {
            $this->get('/workspaces/'.$source.'/documents')->assertOk()->assertSee('Upload document')->assertSee('New folder');
        }
        $this->assertDatabaseCount('sensitive_changes', 0);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_dms_is_not_a_document_source_or_personal_workspace(): void
    {
        $admin = $this->staff(['role' => 'admin', 'source' => 'EMS']);
        $staff = $this->staff(['role' => 'staff', 'source' => 'EMS']);

        $this->actingAs($admin)->get('/my-workspace')->assertNotFound();
        $this->get('/workspaces/dms')->assertNotFound();
        $this->post('/folders', ['source' => 'DMS', 'name' => 'Personal folder'])->assertSessionHasErrors('source');
        $this->post('/documents', ['source' => 'DMS', 'title' => 'Personal file', 'classification' => 'Internal', 'submission_key' => 'dms-upload', 'file' => $this->file()])->assertSessionHasErrors('source');
        $this->post('/documents/batch', ['source' => 'DMS', 'classification' => 'Internal', 'submission_key' => 'dms-batch', 'paths' => ['My folder/batch.txt'], 'files' => [$this->file('batch.txt', 'Batch fixture')]])->assertSessionHasErrors('source');
        $this->assertDatabaseMissing('documents', ['source' => 'DMS']);

        $this->actingAs($staff)->get('/my-workspace')->assertNotFound();
    }

    public function test_administration_navigation_is_distinct_from_personal_workspace(): void
    {
        $super = $this->staff(['role' => 'super_admin']);
        $this->actingAs($super)->get('/admin')->assertOk()->assertSee('School repository')->assertSee('ERP connectors')->assertDontSee('← Administration')->assertDontSee('/my-workspace', false);
        $this->get('/my-workspace')->assertNotFound();
        $this->get('/workspaces/ems')->assertOk()->assertSee('← Administration')->assertSee('Upload document');
        $this->get('/admin/overview')->assertOk()->assertSee('School repository')->assertDontSee('← Administration');
    }

    public function test_operational_admin_manages_only_staff_and_content_in_assigned_workspace(): void
    {
        $super = $this->staff(['role' => 'super_admin', 'source' => 'DMS']);
        $admin = $this->staff(['role' => 'admin', 'source' => 'EMS']);
        $staff = $this->staff(['role' => 'staff', 'source' => 'EMS']);
        $outside = $this->staff(['role' => 'staff', 'source' => 'PMS']);
        $id = $this->upload($staff);
        $this->actingAs($admin)->patch('/documents/'.$id, ['title' => 'Admin reviewed title', 'folder_id' => null, 'revision' => $this->documentRevision($id)])->assertRedirect();
        $this->get('/documents/'.$id)->assertOk()->assertSee('Manage access');
        $this->get('/admin/accounts')->assertOk()->assertSee($staff->email)->assertDontSee($outside->email)->assertDontSee($super->email);
        $data = ['name' => 'New Staff', 'email' => 'scoped.staff@example.test', 'role' => 'staff', 'source' => 'EMS', 'active' => 1];
        $this->post('/admin/accounts', $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        $this->assertDatabaseHas('account_invitations', ['email' => $data['email'], 'status' => 'pending']);
        $created = $this->acceptInvitation($data['email']);
        $this->assertSame('EMS', $created->source);
        foreach (['admin', 'super_admin'] as $role) {
            $this->postJson('/admin/accounts', array_replace($data, ['role' => $role]))->assertForbidden();
        }
        $this->patchJson('/admin/accounts/'.$super->id, array_replace($data, ['email' => $super->email]))->assertNotFound();
        $this->patchJson('/admin/accounts/'.$outside->id, array_replace($data, ['email' => $outside->email]))->assertNotFound();
        $this->get('/admin/connectors')->assertForbidden();
        $this->postJson('/admin/connectors', ['name' => 'Denied', 'source' => 'EMS'])->assertForbidden();
        $this->get('/workspaces/pms')->assertForbidden();
        DB::table('audit_entries')->insert(['school_id' => 'PLP', 'campus' => 'Main', 'source' => 'PMS', 'actor_id' => $outside->id, 'action' => 'PMS_PRIVATE_AUDIT_MARKER', 'details' => '{}', 'created_at' => now()]);
        $this->get('/admin/audit')->assertOk()->assertDontSee('PMS_PRIVATE_AUDIT_MARKER');
        $this->upload($outside, ['source' => 'PMS']);
        $outsideDocument = DB::table('documents')->where('owner_id', $outside->id)->where('source', 'PMS')->value('id');
        DB::table('shares')->insert(['document_id' => $outsideDocument, 'recipient_id' => $admin->id, 'permission' => 'Editor', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin)->patchJson('/documents/'.$outsideDocument, ['title' => 'Denied outside edit', 'folder_id' => null, 'revision' => $this->documentRevision($outsideDocument)])->assertForbidden();

        $this->actingAs($staff)->postJson('/folders', ['source' => 'EMS', 'name' => 'Staff folder'])->assertRedirect();
        $this->assertDatabaseHas('folders', ['name' => 'Staff folder', 'owner_id' => $staff->id]);
        $this->postJson('/documents/batch', [])->assertUnprocessable()->assertJsonValidationErrors(['source', 'files', 'paths']);
        $this->postJson('/documents/'.$id.'/shares', ['recipient_id' => $admin->id, 'permission' => 'Viewer', 'revision' => $this->documentRevision($id)])->assertAccepted();
        $this->assertDatabaseMissing('shares', ['document_id' => $id, 'recipient_id' => $admin->id]);
        $this->get('/documents/'.$id)->assertOk()->assertSee('Manage access')->assertSee('Upload new version');
        $this->get('/workspaces/ems')->assertOk()->assertSee('Upload document')->assertSee('New folder')->assertSee('Upload folder');
        $this->get('/admin')->assertForbidden();
    }

    public function test_staff_sign_in_redirects_to_assigned_workspace(): void
    {
        $staff = $this->staff(['role' => 'staff', 'source' => 'PMS', 'password' => 'SyntheticStaff!2026']);
        $this->post('/login', ['email' => $staff->email, 'password' => 'SyntheticStaff!2026'])->assertRedirect('/workspaces/pms');
    }

    public function test_any_format_intake_covers_extensionless_binary_folder_and_api_files(): void
    {
        $user = $this->staff(['role' => 'admin', 'source' => 'EMS']);
        foreach (['README', 'archive.zip', 'audio.mp3', 'drawing.svg'] as $index => $filename) {
            $this->actingAs($user)->postJson('/documents', ['source' => 'EMS', 'classification' => 'Internal', 'title' => $filename, 'submission_key' => 'format-'.$index, 'file' => $this->file($filename, "Synthetic binary fixture {$index}\x00\x01")])->assertCreated()->assertJsonPath('scan_status', 'Pending');
        }
        $this->postJson('/documents/batch', ['source' => 'EMS', 'classification' => 'Internal', 'submission_key' => 'any-format-folder', 'paths' => ['Archive/package.7z'], 'files' => [$this->file('package.7z', 'Synthetic archive fixture')]])->assertCreated()->assertJsonPath('results.0.scan_status', 'Pending');
        $this->withToken($this->token)->withHeader('Idempotency-Key', 'any-format-api')->postJson('/api/v1/documents', ['title' => 'Binary evidence', 'classification' => 'Internal', 'file' => $this->file('evidence.bin', "\x00\x01\x02")])->assertCreated()->assertJsonPath('scan_status', 'Pending');
        $this->assertDatabaseCount('documents', 6);
        $this->assertDatabaseCount('document_versions', 6);
        Queue::assertPushed(ScanDocumentVersion::class, 6);
        foreach (DB::table('document_versions')->get() as $version) {
            $this->assertTrue(Storage::disk('local')->exists($version->storage_key));
            $this->assertStringStartsWith('documents/', $version->storage_key);
            $this->assertNotEmpty($version->mime);
        }
    }

    public function test_classification_changes_are_validated_revision_checked_and_owner_authorized(): void
    {
        $owner = $this->staff(['role' => 'staff']);
        $id = $this->upload($owner);
        $input = ['title' => 'Synthetic staff file', 'folder_id' => null, 'revision' => 1, 'classification' => 'Restricted'];
        $this->patch('/documents/'.$id, $input)->assertRedirect();
        $this->approvePending();
        $this->assertDatabaseHas('documents', ['id' => $id, 'classification' => 'Restricted', 'revision' => 2]);
        $audit = DB::table('audit_entries')->where('action', 'classification.applied')->where('document_id', $id)->first();
        $this->assertSame('Restricted', json_decode($audit->details, true)['classification']);
        $this->patchJson('/documents/'.$id, array_replace($input, ['revision' => $this->documentRevision($id), 'classification' => 'Public']))->assertUnprocessable();
        $this->patchJson('/documents/'.$id, array_replace($input, ['classification' => 'Internal']))->assertConflict();
        $editor = $this->staff(['role' => 'staff']);
        DB::table('shares')->insert(['document_id' => $id, 'recipient_id' => $editor->id, 'permission' => 'Editor', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($editor)->patchJson('/documents/'.$id, array_replace($input, ['revision' => $this->documentRevision($id), 'classification' => 'Internal']))->assertForbidden();
        $this->assertDatabaseHas('documents', ['id' => $id, 'classification' => 'Restricted', 'revision' => 2]);
    }

    public function test_preview_remains_scan_scoped_pinned_and_never_serves_active_html(): void
    {
        $owner = $this->staff(['role' => 'staff']);
        $id = $this->upload($owner);
        $first = DB::table('document_versions')->where('document_id', $id)->first();
        $this->get('/files/'.$first->id.'/preview')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get('/documents/'.$id)->assertOk()->assertSee('Document preview')->assertSee('id="document-preview-frame"', false)->assertSee('document-classification');
        $this->post('/documents/'.$id.'/versions', ['source' => 'EMS', 'revision' => $this->documentRevision($id), 'submission_key' => 'preview-v2', 'file' => $this->file('second.txt', 'Version two')])->assertRedirect();
        $second = DB::table('document_versions')->where('document_id', $id)->where('number', 2)->first();
        $this->get('/files/'.$second->id.'/preview')->assertStatus(423);
        DB::table('document_versions')->where('id', $second->id)->update(['scan_status' => 'Clean']);
        $viewer = $this->staff(['role' => 'staff', 'source' => 'PMS']);
        DB::table('shares')->insert(['document_id' => $id, 'recipient_id' => $viewer->id, 'permission' => 'Viewer', 'version_id' => $first->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer)->get('/files/'.$first->id.'/preview')->assertOk();
        $this->get('/files/'.$second->id.'/preview')->assertForbidden();
        $this->get('/documents/'.$id)->assertOk()->assertDontSee('id="document-classification"', false);
        DB::table('document_versions')->where('id', $first->id)->update(['mime' => 'text/html']);
        $this->get('/files/'.$first->id.'/preview')->assertStatus(415);
        $other = $this->staff(['role' => 'staff', 'source' => 'ES']);
        $this->actingAs($other)->get('/files/'.$first->id.'/preview')->assertNotFound();
    }

    public function test_text_preview_escapes_html_instead_of_executing_it(): void
    {
        $owner = $this->staff(['role' => 'staff']);
        $id = $this->upload($owner, ['file' => $this->file('example.txt', 'Example: <script>alert(1)</script>')]);
        $version = DB::table('document_versions')->where('document_id', $id)->first();
        DB::table('document_versions')->where('id', $version->id)->update(['mime' => 'text/plain']);
        $this->get('/files/'.$version->id.'/preview')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_trash_success_renders_as_a_top_right_toast_with_countdown(): void
    {
        $owner = $this->staff(['role' => 'staff']);
        $id = $this->upload($owner);
        $message = 'Request submitted. Live document unchanged until approval.';
        $this->post('/documents/'.$id.'/trash', ['revision' => $this->documentRevision($id)])->assertRedirect()->assertSessionHas('status', $message);
        $this->approvePending();
        $this->get('/documents/'.$id)->assertOk()->assertSee($message)->assertSee('position-fixed top-0 end-0', false)->assertSee('toast-countdown', false)->assertDontSee('alert alert-success', false);
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_json_move_preserves_versions_and_checks_destination_revision_and_permissions(): void
    {
        $owner = $this->staff(['role' => 'staff']);
        $id = $this->upload($owner);
        $folder = (string) Str::uuid();
        DB::table('folders')->insert(['id' => $folder, 'owner_id' => $owner->id, 'name' => 'Allowed folder', 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $input = ['title' => 'Synthetic staff file', 'folder_id' => $folder, 'revision' => 1];
        $this->patchJson('/documents/'.$id, $input)->assertOk()->assertJsonPath('folder_id', $folder)->assertJsonPath('revision', 2);
        $this->assertDatabaseHas('documents', ['id' => $id, 'folder_id' => $folder, 'revision' => 2]);
        $this->assertDatabaseCount('document_versions', 1);
        $this->get('/workspaces/ems/documents')->assertOk()->assertViewHas('documents', fn ($rows) => $rows->count() === 0);
        // Inside a folder, navigation out is the "Up to ..." tile rather than the
        // global Back button, which is now reserved for the document preview.
        $this->get('/workspaces/ems/documents?folder='.$folder)->assertOk()->assertSee('Synthetic staff file')->assertDontSee('data-global-back', false)->assertViewHas('folder', fn ($row) => $row->id === $folder)->assertViewHas('parentFolder', fn ($row) => is_null($row));
        $this->get('/documents/'.$id)->assertOk()->assertSee('data-global-back', false);
        $this->patchJson('/documents/'.$id, array_replace($input, ['folder_id' => null]))->assertConflict();
        $other = $this->staff(['role' => 'staff']);
        DB::table('shares')->insert(['document_id' => $id, 'recipient_id' => $other->id, 'permission' => 'Editor', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($other)->patchJson('/documents/'.$id, array_replace($input, ['folder_id' => null, 'revision' => $this->documentRevision($id)]))->assertForbidden();
        $this->actingAs($owner)->patchJson('/documents/'.$id, array_replace($input, ['folder_id' => null, 'revision' => $this->documentRevision($id)]))->assertOk()->assertJsonPath('folder_id', null);
        $badFolder = (string) Str::uuid();
        DB::table('folders')->insert(['id' => $badFolder, 'owner_id' => $owner->id, 'name' => 'Other source', 'source' => 'PMS', 'school_id' => 'PLP', 'campus' => 'Main', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->patchJson('/documents/'.$id, array_replace($input, ['folder_id' => $badFolder, 'revision' => $this->documentRevision($id)]))->assertNotFound();
        $this->assertDatabaseHas('documents', ['id' => $id, 'folder_id' => null, 'revision' => 3]);
        $this->get('/workspaces/ems/documents')->assertOk()->assertSee('Move to folder')->assertSee('data-can-move="true"', false);
    }

    public function test_duplicate_upload_warns_without_leaking_and_accepts_an_intentional_copy(): void
    {
        $user = $this->staff(['role' => 'staff']);
        $id = $this->upload($user);
        $base = ['source' => 'EMS', 'classification' => 'Internal', 'submission_key' => 'duplicate-probe'];

        // A renamed duplicate is still detected, and only a document this user may open is named.
        $this->actingAs($user)->postJson('/documents', $base + ['title' => 'Renamed copy', 'file' => $this->file('renamed.txt')])
            ->assertStatus(409)
            ->assertJsonPath('code', 'duplicate_file')
            ->assertJsonPath('duplicates.0.document_id', $id)
            ->assertJsonPath('duplicates.0.can_add_version', true)
            ->assertJsonMissingPath('duplicates.0.checksum')
            ->assertJsonMissingPath('duplicates.0.filename')
            ->assertJsonMissingPath('duplicates.0.storage_key');
        $this->assertDatabaseCount('documents', 1);
        // Rejected duplicates never leave bytes behind in private storage.
        $this->assertCount(1, Storage::disk('local')->allFiles('documents'));

        // Identical bytes as a new version of the same document are not a duplicate.
        $this->post('/documents/'.$id.'/versions', ['source' => 'EMS', 'revision' => $this->documentRevision($id), 'submission_key' => 'same-bytes', 'file' => $this->file('same.txt')])->assertRedirect();
        $this->assertDatabaseCount('document_versions', 2);

        // The same submission key still succeeds once the caller confirms a separate record.
        $this->actingAs($user)->postJson('/documents', $base + ['title' => 'Renamed copy', 'confirm_duplicate' => true, 'file' => $this->file('renamed.txt')])
            ->assertCreated();
        $this->assertDatabaseCount('documents', 2);
        $confirmed = DB::table('audit_entries')->where('action', 'document.uploaded')->orderByDesc('id')->first();
        $this->assertSame([$id], json_decode($confirmed->details, true)['duplicate_of']);
    }

    public function test_duplicate_checks_respect_hidden_documents_trash_and_pinned_grants(): void
    {
        $owner = $this->staff(['role' => 'staff']);
        $id = $this->upload($owner);
        $outsider = $this->staff(['role' => 'staff', 'source' => 'PMS']);

        // A user who cannot see the document learns nothing and is not blocked.
        $this->actingAs($outsider)->postJson('/documents', ['source' => 'PMS', 'classification' => 'Internal', 'title' => 'My own copy', 'submission_key' => 'outsider-copy', 'file' => $this->file('mine.txt')])->assertCreated();

        // Trashed documents are never offered as an existing copy.
        $this->actingAs($owner)->post('/documents/'.$id.'/trash', ['revision' => $this->documentRevision($id)])->assertRedirect();
        $this->approvePending();
        $this->postJson('/documents', ['source' => 'EMS', 'classification' => 'Internal', 'title' => 'After trash', 'submission_key' => 'after-trash', 'file' => $this->file('after-trash.txt')])->assertCreated();

        // Version-pinned grants hide versions the recipient may not open.
        $pinner = $this->staff();
        $this->upload($pinner, ['file' => $this->file('pinned-v1.txt', 'Pinned version one')]);
        $docId = DB::table('documents')->where('owner_id', $pinner->id)->value('id');
        $this->actingAs($pinner)->post('/documents/'.$docId.'/versions', ['source' => 'EMS', 'revision' => $this->documentRevision($docId), 'submission_key' => 'pinned-v2', 'file' => $this->file('pinned-v2.txt', 'Pinned version two')])->assertRedirect();
        $firstVersion = DB::table('document_versions')->where('document_id', $docId)->where('number', 1)->first();
        $this->post('/documents/'.$docId.'/shares', ['revision' => $this->documentRevision($docId), 'recipient_id' => $outsider->id, 'permission' => 'Viewer', 'version_id' => $firstVersion->id])->assertRedirect();
        $this->approvePending();
        $this->actingAs($outsider)->postJson('/documents', ['source' => 'PMS', 'classification' => 'Internal', 'title' => 'Hidden version bytes', 'submission_key' => 'pinned-probe-1', 'file' => $this->file('probe-a.txt', 'Pinned version two')])->assertCreated();
        $this->actingAs($outsider)->postJson('/documents', ['source' => 'PMS', 'classification' => 'Internal', 'title' => 'Visible version bytes', 'submission_key' => 'pinned-probe-2', 'file' => $this->file('probe-b.txt', 'Pinned version one')])
            ->assertStatus(409)->assertJsonPath('duplicates.0.document_id', $docId);
    }

    public function test_api_duplicate_warning_is_scoped_to_the_connector_source(): void
    {
        $this->withToken($this->token)->withHeader('Idempotency-Key', 'api-dup-1')
            ->postJson('/api/v1/documents', ['title' => 'ERP copy', 'classification' => 'Internal', 'file' => $this->file('erp.txt', 'ERP payload')])->assertCreated();
        $this->withHeader('Idempotency-Key', 'api-dup-2')
            ->postJson('/api/v1/documents', ['title' => 'ERP copy two', 'classification' => 'Internal', 'file' => $this->file('erp2.txt', 'ERP payload')])
            ->assertStatus(409)->assertJsonPath('code', 'duplicate_file');
        $this->withHeader('Idempotency-Key', 'api-dup-3')
            ->postJson('/api/v1/documents', ['title' => 'ERP copy three', 'classification' => 'Internal', 'confirm_duplicate' => true, 'file' => $this->file('erp3.txt', 'ERP payload')])->assertCreated();
        DB::table('connectors')->insert(['name' => 'Test PMS', 'source' => 'PMS', 'school_id' => 'PLP', 'campus' => 'Main', 'token_hash' => hash('sha256', 'pms-token'), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->withToken('pms-token')->withHeader('Idempotency-Key', 'pms-dup-1')
            ->postJson('/api/v1/documents', ['title' => 'PMS copy', 'classification' => 'Internal', 'file' => $this->file('pms.txt', 'ERP payload')])->assertCreated();
        $this->assertDatabaseCount('documents', 3);
    }

    public function test_folder_batch_reports_a_duplicate_per_file_and_accepts_confirmation(): void
    {
        $user = $this->staff();
        $first = ['source' => 'EMS', 'classification' => 'Internal', 'submission_key' => 'batch-dup-1', 'paths' => ['A/template.txt'], 'files' => [$this->file('template.txt', 'Template bytes')]];
        $this->actingAs($user)->postJson('/documents/batch', $first)->assertCreated();
        $second = array_replace($first, ['submission_key' => 'batch-dup-2', 'paths' => ['B/template.txt'], 'files' => [$this->file('template.txt', 'Template bytes')]]);
        $this->postJson('/documents/batch', $second)->assertStatus(207)
            ->assertJsonPath('results.0.status', 409)->assertJsonPath('results.0.code', 'duplicate_file');
        $this->assertDatabaseCount('documents', 1);
        $this->postJson('/documents/batch', array_replace($second, ['submission_key' => 'batch-dup-3', 'confirm_duplicate' => true]))->assertCreated();
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_folder_grants_inherit_to_descendants_and_nearest_permission_wins(): void
    {
        $owner = $this->staff();
        $recipient = $this->staff(['role' => 'staff', 'source' => 'PMS']);
        $this->actingAs($owner)->post('/folders', ['source' => 'EMS', 'name' => 'Policies'])->assertRedirect();
        $root = DB::table('folders')->where('name', 'Policies')->value('id');
        $this->post('/folders', ['source' => 'EMS', 'name' => '2026', 'parent_id' => $root])->assertRedirect();
        $child = DB::table('folders')->where('name', '2026')->value('id');
        $this->upload($owner, ['folder_id' => $root, 'title' => 'Root policy', 'file' => $this->file('root.txt', 'Root bytes')]);
        $rootDoc = DB::table('documents')->where('title', 'Root policy')->value('id');
        $this->upload($owner, ['folder_id' => $child, 'title' => 'Child policy', 'file' => $this->file('child.txt', 'Child bytes')]);
        $childDoc = DB::table('documents')->where('title', 'Child policy')->value('id');

        $this->seedHistoricalFolderGrant($root, ['recipient_id' => $recipient->id, 'permission' => 'Editor']);
        $this->actingAs($owner)->get('/workspaces/ems/documents?folder='.$root)->assertOk()->assertDontSee('Grant folder access');
        $this->actingAs($recipient)->get('/workspaces/pms/documents?view=shared')->assertOk()->assertSee('Root policy');
        $this->get('/workspaces/pms/documents?view=shared')->assertOk()->assertSee('Child policy');
        $this->get('/documents/'.$childDoc)->assertOk()->assertSee('Upload new version');
        $this->post('/documents/'.$childDoc.'/versions', ['source' => 'EMS', 'revision' => $this->documentRevision($childDoc), 'submission_key' => 'inherited-v2', 'file' => $this->file('child-v2.txt', 'Child second')])->assertRedirect();
        // Inherited Editor may add versions, but moving, deleting and resharing stay owner actions.
        $this->postJson('/documents/'.$childDoc.'/trash', ['revision' => $this->documentRevision($childDoc)])->assertForbidden();
        $this->patchJson('/documents/'.$childDoc, ['title' => 'Moved', 'folder_id' => null, 'revision' => $this->documentRevision($childDoc)])->assertForbidden();
        $this->postJson('/documents/'.$childDoc.'/shares', ['recipient_id' => $owner->id, 'permission' => 'Viewer'])->assertForbidden();

        // An explicit document grant always wins over folder inheritance.
        $this->actingAs($owner)->post('/documents/'.$childDoc.'/shares', ['revision' => $this->documentRevision($childDoc), 'recipient_id' => $recipient->id, 'permission' => 'Viewer'])->assertRedirect();
        $this->approvePending();
        $this->actingAs($recipient)->post('/documents/'.$childDoc.'/comments', ['body' => 'Explicit viewer cannot comment'])->assertForbidden();

        // Nearest folder grant wins: Editor on the parent, Viewer on the child.
        $narrow = $this->staff(['role' => 'staff', 'source' => 'ES']);
        $this->actingAs($owner)->seedHistoricalFolderGrant($root, ['recipient_id' => $narrow->id, 'permission' => 'Editor']);
        $this->seedHistoricalFolderGrant($child, ['recipient_id' => $narrow->id, 'permission' => 'Viewer']);
        $this->actingAs($narrow)->post('/documents/'.$rootDoc.'/comments', ['body' => 'Root discussion'])->assertRedirect();
        $this->post('/documents/'.$childDoc.'/comments', ['body' => 'Child comment'])->assertForbidden();
        $this->assertDatabaseCount('comments', 1);

        // Revoking the folder path removes inherited access immediately, but an
        // independent explicit document grant survives.
        $share = DB::table('folder_shares')->where('folder_id', $root)->where('recipient_id', $recipient->id)->first();
        $this->actingAs($owner)->delete('/folders/'.$root.'/shares/'.$share->id)->assertNotFound();
        DB::table('folder_shares')->where('id', $share->id)->update(['revoked_at' => now()]);
        $this->actingAs($recipient)->get('/documents/'.$rootDoc)->assertNotFound();
        $this->get('/documents/'.$childDoc)->assertOk();
    }

    public function test_folder_grant_management_rejects_non_owners_but_allows_named_cross_source_shares(): void
    {
        $owner = $this->staff();
        $staff = $this->staff(['role' => 'staff']);
        $foreignSource = $this->staff(['role' => 'admin', 'source' => 'PMS']);
        $otherCampus = $this->staff(['role' => 'staff', 'campus' => 'Other']);
        $this->actingAs($owner)->post('/folders', ['source' => 'EMS', 'name' => 'Restricted folder'])->assertRedirect();
        $folder = DB::table('folders')->where('name', 'Restricted folder')->value('id');

        $this->actingAs($staff)->post('/folders/'.$folder.'/shares', ['recipient_id' => $staff->id, 'permission' => 'Viewer'])->assertNotFound();
        $this->actingAs($foreignSource)->post('/folders/'.$folder.'/shares', ['recipient_id' => $foreignSource->id, 'permission' => 'Viewer'])->assertNotFound();
        $this->actingAs($owner)->post('/folders/'.$folder.'/shares', ['recipient_id' => $otherCampus->id, 'permission' => 'Viewer'])->assertNotFound();
        // Named shares are the documented exception to ERP source isolation.
        $this->seedHistoricalFolderGrant($folder, ['recipient_id' => $foreignSource->id, 'permission' => 'Viewer']);
        $this->post('/folders/'.$folder.'/shares', ['recipient_id' => $owner->id, 'permission' => 'Viewer'])->assertNotFound();
        $this->assertDatabaseHas('folder_shares', ['folder_id' => $folder, 'recipient_id' => $foreignSource->id, 'permission' => 'Viewer']);
        $this->assertDatabaseCount('folder_shares', 1);
    }

    public function test_expired_folder_grant_grants_nothing(): void
    {
        $owner = $this->staff();
        $recipient = $this->staff(['role' => 'staff', 'source' => 'PMS']);
        $this->actingAs($owner)->post('/folders', ['source' => 'EMS', 'name' => 'Expired access'])->assertRedirect();
        $folder = DB::table('folders')->where('name', 'Expired access')->value('id');
        $this->upload($owner, ['folder_id' => $folder, 'title' => 'Expired document', 'file' => $this->file('expired.txt', 'Expired bytes')]);
        $doc = DB::table('documents')->where('title', 'Expired document')->value('id');
        DB::table('folder_shares')->insert(['folder_id' => $folder, 'recipient_id' => $recipient->id, 'permission' => 'Viewer', 'expires_at' => now()->subMinute(), 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($recipient)->get('/documents/'.$doc)->assertNotFound();
        $this->get('/workspaces/pms/documents?view=shared')->assertOk()->assertDontSee('Expired document');
    }

    public function test_folder_grant_does_not_expose_hidden_documents_or_other_sources(): void
    {
        $owner = $this->staff();
        $recipient = $this->staff(['role' => 'staff', 'source' => 'PMS']);
        $this->actingAs($owner)->post('/folders', ['source' => 'EMS', 'name' => 'Visible folder'])->assertRedirect();
        $visible = DB::table('folders')->where('name', 'Visible folder')->value('id');
        $this->post('/folders', ['source' => 'EMS', 'name' => 'Hidden folder'])->assertRedirect();
        $hidden = DB::table('folders')->where('name', 'Hidden folder')->value('id');
        $this->upload($owner, ['folder_id' => $visible, 'title' => 'Inherited doc', 'file' => $this->file('inherited.txt', 'Inherited bytes')]);
        $inherited = DB::table('documents')->where('title', 'Inherited doc')->value('id');
        $this->upload($owner, ['folder_id' => $hidden, 'title' => 'Hidden doc', 'file' => $this->file('hidden.txt', 'Hidden bytes')]);
        $hiddenDoc = DB::table('documents')->where('title', 'Hidden doc')->value('id');
        $this->seedHistoricalFolderGrant($visible, ['recipient_id' => $recipient->id, 'permission' => 'Viewer']);

        $this->actingAs($recipient)->get('/documents/'.$inherited)->assertOk();
        $this->get('/documents/'.$hiddenDoc)->assertNotFound();
        $this->get('/workspaces/pms/documents?view=shared')->assertOk()->assertSee('Inherited doc')->assertDontSee('Hidden doc');
    }

    public function test_moves_between_shared_folders_warn_before_broadening_access_and_update_inheritance(): void
    {
        $owner = $this->staff();
        $sourceRecipient = $this->staff(['role' => 'staff', 'source' => 'PMS']);
        $destinationRecipient = $this->staff(['role' => 'staff', 'source' => 'ES']);
        $this->actingAs($owner)->post('/folders', ['source' => 'EMS', 'name' => 'Source folder'])->assertRedirect();
        $source = DB::table('folders')->where('name', 'Source folder')->value('id');
        $this->post('/folders', ['source' => 'EMS', 'name' => 'Destination folder'])->assertRedirect();
        $destination = DB::table('folders')->where('name', 'Destination folder')->value('id');
        $this->upload($owner, ['folder_id' => $source, 'title' => 'Moved policy', 'file' => $this->file('move.txt', 'Move bytes')]);
        $doc = DB::table('documents')->where('title', 'Moved policy')->value('id');
        $this->seedHistoricalFolderGrant($source, ['recipient_id' => $sourceRecipient->id, 'permission' => 'Viewer']);
        $this->seedHistoricalFolderGrant($destination, ['recipient_id' => $destinationRecipient->id, 'permission' => 'Commenter']);

        // The move is refused before any write until the owner confirms the broader access.
        $this->actingAs($owner)->patchJson('/documents/'.$doc, ['title' => 'Moved policy', 'folder_id' => $destination, 'revision' => $this->documentRevision($doc)])
            ->assertStatus(409)->assertJsonPath('code', 'access_change')->assertJsonPath('access.gained', 1)
            ->assertJsonPath('access.folder', 'Destination folder');
        $this->assertDatabaseHas('documents', ['id' => $doc, 'folder_id' => $source, 'revision' => 1]);
        $this->assertDatabaseCount('document_versions', 1);

        // Confirmed move re-scopes inherited access without new grants.
        $this->patchJson('/documents/'.$doc, ['title' => 'Moved policy', 'folder_id' => $destination, 'revision' => $this->documentRevision($doc), 'confirm_move' => true])
            ->assertOk()->assertJsonPath('folder_id', $destination)->assertJsonPath('access_gained', 1);
        $this->actingAs($sourceRecipient)->get('/documents/'.$doc)->assertNotFound();
        $this->actingAs($destinationRecipient)->get('/documents/'.$doc)->assertOk();
        $this->post('/documents/'.$doc.'/comments', ['body' => 'Destination comment'])->assertRedirect();

        $audit = DB::table('audit_entries')->where('document_id', $doc)->where('action', 'document.updated')->first();
        $details = json_decode($audit->details, true);
        $this->assertSame($source, $details['folder_before']);
        $this->assertSame($destination, $details['folder_after']);
        $this->assertEquals([$destinationRecipient->id], $details['access_gained']);
        $this->assertDatabaseHas('integration_events', ['document_id' => $doc, 'type' => 'document.moved']);

        // Moving back out removes the inherited path again; versions stay untouched.
        $this->actingAs($owner)->patchJson('/documents/'.$doc, ['title' => 'Moved policy', 'folder_id' => null, 'revision' => $this->documentRevision($doc)])
            ->assertOk()->assertJsonPath('folder_id', null);
        $this->actingAs($destinationRecipient)->get('/documents/'.$doc)->assertNotFound();
        $this->assertDatabaseCount('document_versions', 1);
    }

    public function test_deactivation_and_grant_revocation_remove_session_and_inherited_access(): void
    {
        $owner = $this->staff();
        $super = $this->staff(['role' => 'super_admin']);
        $recipient = $this->staff(['role' => 'staff', 'source' => 'PMS']);
        $this->actingAs($owner)->post('/folders', ['source' => 'EMS', 'name' => 'Revocable folder'])->assertRedirect();
        $folder = DB::table('folders')->where('name', 'Revocable folder')->value('id');
        $this->upload($owner, ['folder_id' => $folder, 'title' => 'Revocable document', 'file' => $this->file('revoke.txt', 'Revoke bytes')]);
        $doc = DB::table('documents')->where('title', 'Revocable document')->value('id');
        $this->seedHistoricalFolderGrant($folder, ['recipient_id' => $recipient->id, 'permission' => 'Editor']);
        $this->actingAs($recipient)->get('/documents/'.$doc)->assertOk();

        $payload = ['name' => $recipient->name, 'email' => $recipient->email, 'role' => 'staff', 'source' => 'PMS', 'active' => 0];
        $this->actingAs($super)->patch('/admin/accounts/'.$recipient->id, $payload)->assertRedirect();
        $this->actingAs($recipient->refresh())->get('/documents/'.$doc)->assertRedirect('/login');

        // Reactivation restores the retained, still-audited grant.
        $this->actingAs($super)->patch('/admin/accounts/'.$recipient->id, array_replace($payload, ['active' => 1]))->assertRedirect();
        $this->actingAs($recipient->refresh())->get('/documents/'.$doc)->assertOk();

        // Revoking the folder grant ends inherited access on the next request.
        $share = DB::table('folder_shares')->where('folder_id', $folder)->where('recipient_id', $recipient->id)->first();
        $this->actingAs($owner)->delete('/folders/'.$folder.'/shares/'.$share->id)->assertNotFound();
        DB::table('folder_shares')->where('id', $share->id)->update(['revoked_at' => now()]);
        $this->actingAs($recipient->refresh())->get('/documents/'.$doc)->assertNotFound();
    }

    public function test_revoking_an_explicit_grant_falls_back_to_inherited_folder_access(): void
    {
        $owner = $this->staff();
        $recipient = $this->staff(['role' => 'staff']);
        $this->actingAs($owner)->post('/folders', ['source' => 'EMS', 'name' => 'Inherited folder'])->assertRedirect();
        $folder = DB::table('folders')->where('name', 'Inherited folder')->value('id');
        $this->upload($owner, ['folder_id' => $folder, 'title' => 'Fallback document', 'file' => $this->file('fallback.txt', 'Fallback bytes')]);
        $doc = DB::table('documents')->where('title', 'Fallback document')->value('id');
        $this->seedHistoricalFolderGrant($folder, ['recipient_id' => $recipient->id, 'permission' => 'Viewer']);
        $this->post('/documents/'.$doc.'/shares', ['revision' => $this->documentRevision($doc), 'recipient_id' => $recipient->id, 'permission' => 'Editor'])->assertRedirect();
        $this->approvePending();
        $this->actingAs($recipient)->post('/documents/'.$doc.'/comments', ['body' => 'Explicit editor'])->assertRedirect();
        $this->assertDatabaseCount('comments', 1);

        $explicit = DB::table('shares')->where('document_id', $doc)->where('recipient_id', $recipient->id)->first();
        $this->actingAs($owner)->delete('/documents/'.$doc.'/shares/'.$explicit->id, ['revision' => $this->documentRevision($doc)])->assertRedirect();
        $this->approvePending();
        $this->actingAs($recipient)->get('/documents/'.$doc)->assertOk();
        $this->actingAs($recipient)->post('/documents/'.$doc.'/comments', ['body' => 'Viewer cannot comment'])->assertForbidden();
        $this->assertDatabaseCount('comments', 1);
    }

    public function test_moves_and_revocations_preserve_versions_audit_and_private_files(): void
    {
        $owner = $this->staff();
        $recipient = $this->staff(['role' => 'staff', 'source' => 'PMS']);
        $this->actingAs($owner)->post('/folders', ['source' => 'EMS', 'name' => 'Preserve source'])->assertRedirect();
        $source = DB::table('folders')->where('name', 'Preserve source')->value('id');
        $this->post('/folders', ['source' => 'EMS', 'name' => 'Preserve target'])->assertRedirect();
        $target = DB::table('folders')->where('name', 'Preserve target')->value('id');
        $this->upload($owner, ['folder_id' => $source, 'title' => 'Preserved document', 'file' => $this->file('v1.txt', 'Preserved bytes')]);
        $doc = DB::table('documents')->where('title', 'Preserved document')->value('id');
        $this->actingAs($owner)->post('/documents/'.$doc.'/versions', ['source' => 'EMS', 'revision' => $this->documentRevision($doc), 'submission_key' => 'preserve-v2', 'file' => $this->file('v2.txt', 'Preserved second')])->assertRedirect();
        $versions = DB::table('document_versions')->where('document_id', $doc)->orderBy('number')->get(['id', 'number', 'checksum', 'storage_key']);
        $this->assertCount(2, $versions);
        foreach ($versions as $version) {
            $this->assertTrue(Storage::disk('local')->exists($version->storage_key));
            $this->assertSame($version->checksum, hash_file('sha256', Storage::disk('local')->path($version->storage_key)));
        }
        $auditBefore = DB::table('audit_entries')->where('document_id', $doc)->pluck('id');

        $this->seedHistoricalFolderGrant($target, ['recipient_id' => $recipient->id, 'permission' => 'Viewer']);
        $this->actingAs($owner)->patchJson('/documents/'.$doc, ['title' => 'Preserved document', 'folder_id' => $target, 'revision' => $this->documentRevision($doc), 'confirm_move' => true])->assertOk();
        $this->actingAs($recipient)->get('/files/'.$versions[0]->id)->assertOk();
        $this->actingAs($recipient)->get('/files/'.$versions[1]->id)->assertStatus(423);

        $share = DB::table('folder_shares')->where('folder_id', $target)->where('recipient_id', $recipient->id)->first();
        $this->actingAs($owner)->delete('/folders/'.$target.'/shares/'.$share->id)->assertNotFound();
        DB::table('folder_shares')->where('id', $share->id)->update(['revoked_at' => now()]);
        $this->actingAs($recipient)->get('/files/'.$versions[0]->id)->assertNotFound();

        // Immutable versions, private bytes and earlier audit rows survive every operation.
        $this->assertSame($versions->map(fn ($v) => (array) $v)->all(),
            DB::table('document_versions')->where('document_id', $doc)->orderBy('number')->get(['id', 'number', 'checksum', 'storage_key'])->map(fn ($v) => (array) $v)->all());
        foreach ($versions as $version) {
            $this->assertTrue(Storage::disk('local')->exists($version->storage_key));
        }
        foreach ($auditBefore as $id) {
            $this->assertDatabaseHas('audit_entries', ['id' => $id]);
        }
        $this->assertGreaterThan($auditBefore->count(), DB::table('audit_entries')->where('document_id', $doc)->count());
        foreach (['document.uploaded', 'document.updated'] as $action) {
            $this->assertDatabaseHas('audit_entries', ['document_id' => $doc, 'action' => $action]);
        }
    }

    public function test_demo_seeder_creates_one_super_admin_admin_and_staff_per_source(): void
    {
        config(['dms.demo_password' => 'SyntheticDemo!2026']);
        $this->seed();

        $this->assertSame(['EMS' => 'Employee Management', 'PMS' => 'Payroll Management', 'ES' => 'Enrollment', 'OAS' => 'Online Admission'], config('dms.sources'));
        $this->assertSame(1, User::where('role', 'super_admin')->count());
        $this->assertNull(User::where('role', 'super_admin')->value('source'));
        $this->assertSame(0, User::where('source', 'DMS')->count());
        $this->assertSame(0, User::whereNotIn('source', Source::values())->whereNotNull('source')->count());

        foreach (Source::values() as $source) {
            $this->assertSame(1, User::where('role', 'admin')->where('source', $source)->count(), $source.' admin');
            $this->assertSame(1, User::where('role', 'staff')->where('source', $source)->count(), $source.' staff');
            $this->assertSame(2, User::where('source', $source)->count(), $source.' accounts');
        }

        $this->assertSame(1, User::where('role', 'super_admin')->where('school_id', 'PLP')->where('campus', 'Main')->count());
        $this->assertTrue(Hash::check('SyntheticDemo!2026', User::where('email', 'ems@plpasig.edu.ph')->value('password')));
    }

    public function test_super_admin_is_source_less_and_legacy_roles_map_to_staff_sources(): void
    {
        $super = $this->staff(['role' => 'super_admin', 'source' => 'DMS']);
        $this->assertNull($super->fresh()->source);
        $this->assertEqualsCanonicalizing(['EMS', 'PMS', 'ES', 'OAS'], $super->sources());
        $this->assertTrue($super->canAccessSource('OAS'));
        $this->assertSame('/admin', $super->homePath());

        foreach ([['employee', 'EMS'], ['teacher', 'EMS'], ['hr', 'EMS'], ['payroll', 'PMS']] as [$role, $source]) {
            $legacy = new User(['role' => $role, 'school_id' => 'PLP', 'campus' => 'Main']);
            $this->assertTrue($legacy->isStaff(), $role);
            $this->assertSame([$source], $legacy->sources(), $role);
            $this->assertSame('/workspaces/'.strtolower($source), $legacy->homePath(), $role);
        }

        $legacyAdmin = new User(['role' => 'admin']);
        $this->assertTrue($legacyAdmin->isAdmin());
        $this->assertFalse($legacyAdmin->isStaff());
        $this->assertSame([], $legacyAdmin->sources());

        $registrar = new User(['role' => 'registrar']);
        $this->assertFalse($registrar->isStaff());
        $this->assertSame([], $registrar->sources());
        $this->assertSame('/account', $registrar->homePath());
    }

    public function test_connector_ids_never_match_user_folder_owners_or_grants(): void
    {
        // The test connector has id 1; the EMS admin also gets id 1 accidentally,
        // which is exactly the fixture needed to regression-test the collision.
        $emsAdmin = $this->staff(['role' => 'admin', 'source' => 'EMS']);
        $this->actingAs($emsAdmin)->post('/folders', ['source' => 'EMS', 'name' => 'EMS shared'])->assertRedirect();
        $emsFolder = DB::table('folders')->where('name', 'EMS shared')->value('id');

        // An integration connector never owns a user folder, even when its id matches.
        $this->withToken($this->token)->withHeader('Idempotency-Key', 'connector-folder-collision')
            ->postJson('/api/v1/documents', ['title' => 'Collision upload', 'classification' => 'Internal', 'folder_id' => $emsFolder, 'file' => $this->file()])
            ->assertForbidden();
        $this->assertDatabaseCount('documents', 0);

        // A user's folder grant is not inherited by a connector with the same id.
        $pmsAdmin = $this->staff(['role' => 'admin', 'source' => 'PMS']);
        $this->actingAs($pmsAdmin)->post('/folders', ['source' => 'PMS', 'name' => 'PMS granted'])->assertRedirect();
        $pmsFolder = DB::table('folders')->where('name', 'PMS granted')->value('id');
        $document = $this->upload($pmsAdmin, ['source' => 'PMS', 'folder_id' => $pmsFolder, 'title' => 'PMS private file']);
        $version = DB::table('document_versions')->where('document_id', $document)->first();
        $recipient = $this->staff(['role' => 'staff', 'source' => 'EMS']);
        $this->actingAs($pmsAdmin)->seedHistoricalFolderGrant($pmsFolder, ['recipient_id' => $recipient->id, 'permission' => 'Viewer']);
        DB::table('connectors')->where('id', 1)->update(['id' => $recipient->id]);

        $this->withToken($this->token)->get('/api/v1/files/'.$version->id)->assertNotFound();
    }
}
