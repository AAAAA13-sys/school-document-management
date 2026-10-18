<?php

namespace Tests\Feature;

use App\Jobs\ScanDocumentVersion;
use App\Models\User;
use App\Services\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
        $this->post('/documents/'.$id.'/versions', ['source' => 'EMS', 'revision' => 1, 'submission_key' => 'version-2', 'file' => $this->file('next.txt', 'New contents')])->assertRedirect();
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
        $version=DB::table('document_versions')->first();
        $this->get('/files/'.$version->id)->assertStatus(423);
        $this->mock(Scanner::class, fn ($m) => $m->shouldReceive('scan')->andReturn('Clean'));
        (new ScanDocumentVersion($version->id))->handle(app(Scanner::class));
        $download=$this->get('/files/'.$version->id)->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
        $this->assertStringContainsString('attachment',$download->headers->get('Content-Disposition'));
        $this->post('/documents', array_replace($base, ['submission_key'=>'oversized','file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')]))->assertSessionHasErrors('file');
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
            $this->post('/documents/'.$id.'/shares', ['recipient_id' => $owner->id, 'permission' => 'Editor'])->assertForbidden();
        }
    }

    public function test_editor_can_rename_and_version_but_cannot_move_trash_or_reshare(): void
    {
        $owner = $this->staff();
        $id = $this->upload($owner);
        $editor = $this->staff(['role' => 'staff']);
        $recipient = $this->staff();
        $this->actingAs($owner)->post('/documents/'.$id.'/shares', ['recipient_id' => $editor->id, 'permission' => 'Editor'])->assertRedirect();
        $this->actingAs($editor)->patch('/documents/'.$id, ['title' => 'Renamed', 'revision' => 1, 'folder_id' => null])->assertRedirect();
        $this->post('/documents/'.$id.'/trash', ['revision' => 2])->assertForbidden();
        $this->post('/documents/'.$id.'/shares', ['recipient_id' => $recipient->id, 'permission' => 'Viewer'])->assertForbidden();
        $this->post('/documents/'.$id.'/versions', ['source' => 'EMS', 'revision' => 2, 'submission_key' => 'editor-v2', 'file' => $this->file()])->assertRedirect();
        $this->assertDatabaseCount('document_versions', 2);
        $this->post('/documents/'.$id.'/comments', ['body' => 'A valid comment'])->assertRedirect();
    }

    public function test_viewer_comments_expiry_revocation_and_pinned_versions(): void
    {
        $owner = $this->staff();
        $id = $this->upload($owner);
        $viewer = $this->staff(['role' => 'staff']);
        $v = DB::table('document_versions')->first();
        $this->post('/documents/'.$id.'/shares', ['recipient_id' => $viewer->id, 'permission' => 'Viewer', 'version_id' => $v->id])->assertRedirect();
        $this->post('/documents/'.$id.'/versions', ['source' => 'EMS', 'revision' => 1, 'submission_key' => 'new', 'file' => $this->file('next.txt')])->assertRedirect();
        $latest = DB::table('document_versions')->where('number', 2)->first();
        $this->actingAs($viewer)->get('/files/'.$v->id)->assertOk();
        $this->get('/files/'.$latest->id)->assertForbidden();
        $this->post('/documents/'.$id.'/comments', ['body' => 'Not allowed'])->assertForbidden();
        $share = DB::table('shares')->first();
        $this->actingAs($owner)->delete('/documents/'.$id.'/shares/'.$share->id)->assertRedirect();
        // Employees may still read their own documents, but a revoked recipient loses this file.
        $other = $this->staff(['role' => 'staff', 'source' => 'OAS']);
        $this->post('/documents/'.$id.'/shares', ['recipient_id' => $other->id, 'permission' => 'Commenter', 'expires_at' => now()->addHour()->toDateTimeString()])->assertRedirect();
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
        $this->post('/documents/'.$id.'/trash', ['revision' => 1])->assertRedirect();
        $this->get('/files/'.$v->id)->assertNotFound();
        $this->post('/documents/'.$id.'/restore', ['revision' => 2])->assertRedirect();
        $this->get('/files/'.$v->id)->assertOk();
        $this->assertDatabaseCount('document_versions', 1);
        $this->assertDatabaseHas('audit_entries', ['action' => 'document.trashed', 'document_id' => $id]);
        $this->assertDatabaseHas('audit_entries', ['action' => 'document.restored', 'document_id' => $id]);
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
        $other = $this->staff(['source'=>'PMS']);
        $this->actingAs($other)->post('/folders', ['name' => 'Outside', 'source' => 'EMS', 'parent_id' => $parent])->assertForbidden();
    }

    public function test_accounts_inherit_scope_and_self_lockout_is_prevented(): void
    {
        $admin = $this->staff(['role' => 'super_admin']);
        $data = ['name' => 'Synthetic colleague', 'email' => 'colleague@example.test', 'password' => 'SyntheticPass!123', 'role' => 'admin', 'source' => 'EMS', 'active' => 1, 'school_id' => 'FORGED'];
        $this->actingAs($admin)->post('/admin/accounts', $data)->assertRedirect();
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
        $files = fn () => ['files' => [$this->file('one.txt'), $this->file('two.txt')]];
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

    public function test_regrant_can_remove_an_old_expiry_and_pinned_version(): void
    {
        $owner = $this->staff();
        $id = $this->upload($owner);
        $recipient = $this->staff(['role' => 'staff']);
        $v = DB::table('document_versions')->first();
        $this->post('/documents/'.$id.'/shares', ['recipient_id' => $recipient->id, 'permission' => 'Viewer', 'version_id' => $v->id, 'expires_at' => now()->addDay()->toDateTimeString()])->assertRedirect();
        $this->post('/documents/'.$id.'/shares', ['recipient_id' => $recipient->id, 'permission' => 'Editor'])->assertRedirect();
        $this->assertDatabaseHas('shares', ['document_id' => $id, 'recipient_id' => $recipient->id, 'permission' => 'Editor', 'version_id' => null, 'expires_at' => null]);
        $this->actingAs($recipient)->patch('/documents/'.$id, ['title' => 'Editor regrant works', 'revision' => 1])->assertRedirect();
    }

    public function test_preview_is_opt_in_and_carries_sandbox_headers(): void
    {
        $id = $this->upload($this->staff());
        $version = DB::table('document_versions')->first();
        config(['dms.preview_mimes'=>[]]);
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
        $user=$this->staff();
        $this->actingAs($user)->post('/folders',['name'=>'Counted folder','source'=>'EMS'])->assertRedirect();
        $folder=DB::table('folders')->value('id');
        $id=$this->upload($user,['folder_id'=>$folder]);
        $this->get('/workspaces/ems/documents')->assertViewHas('folders',fn($folders)=>(int)($folders->firstWhere('id',$folder)->document_count??0)===1);
        $this->post('/documents/'.$id.'/trash',['revision'=>1])->assertRedirect();
        $this->get('/workspaces/ems/documents')->assertViewHas('folders',fn($folders)=>(int)($folders->firstWhere('id',$folder)->document_count??0)===0);
    }

    public function test_admin_account_uses_dms_workspace_without_losing_erp_access(): void
    {
        $admin=$this->staff(['role'=>'super_admin']);
        $data=['name'=>'New Admin','email'=>'new.admin@example.test','password'=>'SyntheticAdmin!2026','role'=>'super_admin','source'=>'EMS','active'=>1];
        $this->actingAs($admin)->post('/admin/accounts',$data)->assertRedirect()->assertSessionHasNoErrors();
        $created=User::where('email',$data['email'])->firstOrFail();
        $this->assertSame('DMS',$created->source);
        $this->assertSame(['EMS','PMS','ES','OAS'],$created->sources());
        $this->actingAs($created)->get('/account')->assertOk()->assertSee('DMS ADMINISTRATION')->assertSee('Personal DMS workspace + read-only ERP oversight')->assertSee('account-grid',false);
        foreach (['ems','pms','es','oas'] as $source) $this->get('/workspaces/'.$source)->assertOk();
        $data['email']='employee@example.test';$data['role']='staff';$data['source']='DMS';
        $this->post('/admin/accounts',$data)->assertSessionHasErrors('source');
    }

    public function test_inactive_connector_displays_not_connected(): void
    {
        DB::table('connectors')->update(['active'=>false]);
        $this->actingAs($this->staff(['role'=>'super_admin','source'=>'DMS']))->get('/admin/connectors')->assertOk()->assertSee('Not connected')->assertDontSee('Revoked');
    }

    public function test_super_admin_repository_oversight_blocks_every_write_even_on_owned_documents(): void
    {
        $staff=$this->staff();
        $this->upload($staff);
        $doc=DB::table('documents')->first();
        $admin=$this->staff(['role'=>'super_admin','source'=>'DMS']);
        DB::table('documents')->where('id',$doc->id)->update(['owner_id'=>$admin->id]);
        $this->actingAs($admin)->get('/documents/'.$doc->id)->assertOk()->assertSee('Read-only oversight')->assertDontSee('Upload new version')->assertDontSee('Manage access')->assertDontSee('Post comment')->assertDontSee('Move to trash');
        foreach ([['POST','/documents'],['POST','/documents/batch'],['POST','/documents/'.$doc->id.'/versions'],['PATCH','/documents/'.$doc->id],['POST','/documents/'.$doc->id.'/trash'],['POST','/documents/'.$doc->id.'/restore'],['POST','/documents/'.$doc->id.'/star'],['POST','/documents/'.$doc->id.'/shares'],['DELETE','/documents/'.$doc->id.'/shares/1'],['POST','/documents/'.$doc->id.'/comments'],['POST','/folders'],['PATCH','/folders/fixture']] as [$method,$url]) {
            $this->json($method,$url,[])->assertForbidden();
        }
        $this->assertDatabaseHas('documents',['id'=>$doc->id,'revision'=>1,'trashed_at'=>null]);
        $this->get('/admin/accounts')->assertOk();
        $this->get('/admin/connectors')->assertOk();
        foreach (['ems','pms','es','oas'] as $source) $this->get('/workspaces/'.$source.'/documents')->assertOk()->assertSee('Read-only oversight')->assertDontSee('Upload document')->assertDontSee('Upload folder')->assertDontSee('New folder');
        $this->get('/documents/'.$doc->id.'/scan-status')->assertOk();
    }

    public function test_admin_can_manage_personal_dms_files_without_changing_erp_repositories(): void
    {
        $admin=$this->staff(['role'=>'super_admin','source'=>'DMS']);
        $recipient=$this->staff(['role'=>'staff']);
        $this->actingAs($admin)->get('/my-workspace')->assertOk()->assertSee('Upload document')->assertDontSee('Read-only oversight')->assertSee('My documents')->assertSee('Folders');
        $this->post('/folders',['source'=>'DMS','name'=>'Personal folder'])->assertRedirect()->assertSessionHasNoErrors();
        $folder=DB::table('folders')->where('owner_id',$admin->id)->first();
        $id=$this->upload($admin,['source'=>'DMS','folder_id'=>$folder->id]);
        $this->assertDatabaseHas('documents',['id'=>$id,'owner_id'=>$admin->id,'source'=>'DMS']);
        $this->get('/my-workspace/documents?folder='.$folder->id)->assertOk()->assertSee('Synthetic staff file');
        $this->get('/documents/'.$id)->assertOk()->assertSee('Upload new version')->assertSee('Manage access')->assertSee('Post comment')->assertSee('Move to trash');
        $this->patch('/documents/'.$id,['title'=>'My personal document','folder_id'=>$folder->id,'revision'=>1])->assertRedirect();
        $this->post('/documents/'.$id.'/versions',['source'=>'DMS','revision'=>2,'submission_key'=>'personal-v2','file'=>$this->file('v2.txt','Version two')])->assertRedirect();
        $this->post('/documents/'.$id.'/comments',['body'=>'Personal workspace note'])->assertRedirect();
        $this->post('/documents/'.$id.'/shares',['recipient_id'=>$recipient->id,'permission'=>'Viewer'])->assertRedirect();
        $share=DB::table('shares')->where('document_id',$id)->first();
        $this->delete('/documents/'.$id.'/shares/'.$share->id)->assertRedirect();
        $this->post('/documents/'.$id.'/star')->assertRedirect();
        $this->post('/documents/'.$id.'/trash',['revision'=>3])->assertRedirect();
        $this->post('/documents/'.$id.'/restore',['revision'=>4])->assertRedirect();
        $this->assertDatabaseHas('documents',['id'=>$id,'revision'=>5,'trashed_at'=>null]);
        $this->post('/documents/batch',['source'=>'DMS','classification'=>'Internal','submission_key'=>'personal-batch','paths'=>['My folder/batch.txt'],'files'=>[$this->file('batch.txt','Batch fixture')]])->assertCreated();
        foreach (['EMS','PMS','ES','OAS'] as $source) $this->postJson('/documents',['source'=>$source])->assertForbidden();
        $this->actingAs($recipient)->get('/my-workspace')->assertForbidden();
        $this->post('/documents',['source'=>'DMS','title'=>'Denied','classification'=>'Internal','submission_key'=>'staff-dms','file'=>$this->file()])->assertForbidden();
        $other=$this->staff(['role'=>'super_admin','source'=>'DMS']);
        $this->actingAs($other)->get('/documents/'.$id)->assertNotFound();
        $this->post('/documents/'.$id.'/trash',['revision'=>5])->assertForbidden();
        $this->get('/my-workspace/documents')->assertOk()->assertDontSee('My personal document');
    }

    public function test_administration_navigation_is_distinct_from_personal_workspace(): void
    {
        $super=$this->staff(['role'=>'super_admin','source'=>'DMS']);
        $this->actingAs($super)->get('/admin')->assertOk()->assertSee('School repository')->assertSee('ERP connectors')->assertDontSee('← Administration')->assertDontSee('/my-workspace/overview',false);
        $this->get('/my-workspace')->assertOk()->assertSee('← Administration')->assertSee('href="/admin"',false);
        $this->get('/workspaces/ems')->assertOk()->assertSee('← Administration')->assertSee('Read-only oversight');
        $this->get('/admin/overview')->assertOk()->assertSee('School repository')->assertDontSee('← Administration');
    }

    public function test_operational_admin_manages_only_staff_and_content_in_assigned_workspace(): void
    {
        $super=$this->staff(['role'=>'super_admin','source'=>'DMS']);
        $admin=$this->staff(['role'=>'admin','source'=>'EMS']);
        $staff=$this->staff(['role'=>'staff','source'=>'EMS']);
        $outside=$this->staff(['role'=>'staff','source'=>'PMS']);
        $id=$this->upload($staff);
        $this->actingAs($admin)->patch('/documents/'.$id,['title'=>'Admin reviewed title','folder_id'=>null,'revision'=>1])->assertRedirect();
        $this->get('/documents/'.$id)->assertOk()->assertSee('Manage access');
        $this->get('/admin/accounts')->assertOk()->assertSee($staff->email)->assertDontSee($outside->email)->assertDontSee($super->email);
        $data=['name'=>'New Staff','email'=>'scoped.staff@example.test','role'=>'staff','source'=>'EMS','password'=>'SyntheticStaff!2026','active'=>1];
        $this->post('/admin/accounts',$data)->assertRedirect()->assertSessionHasNoErrors();
        foreach (['admin','super_admin'] as $role) $this->postJson('/admin/accounts',array_replace($data,['role'=>$role]))->assertForbidden();
        $this->patchJson('/admin/accounts/'.$super->id,array_replace($data,['email'=>$super->email]))->assertForbidden();
        $this->patchJson('/admin/accounts/'.$outside->id,array_replace($data,['email'=>$outside->email]))->assertForbidden();
        $this->get('/admin/connectors')->assertForbidden();
        $this->postJson('/admin/connectors',['name'=>'Denied','source'=>'EMS'])->assertForbidden();
        $this->get('/workspaces/pms')->assertForbidden();
        DB::table('audit_entries')->insert(['school_id'=>'PLP','campus'=>'Main','source'=>'PMS','actor_id'=>$outside->id,'action'=>'PMS_PRIVATE_AUDIT_MARKER','details'=>'{}','created_at'=>now()]);
        $this->get('/admin/audit')->assertOk()->assertDontSee('PMS_PRIVATE_AUDIT_MARKER');
        $this->upload($outside,['source'=>'PMS']);
        $outsideDocument=DB::table('documents')->where('owner_id',$outside->id)->where('source','PMS')->value('id');
        DB::table('shares')->insert(['document_id'=>$outsideDocument,'recipient_id'=>$admin->id,'permission'=>'Editor','created_at'=>now(),'updated_at'=>now()]);
        $this->actingAs($admin)->patchJson('/documents/'.$outsideDocument,['title'=>'Denied outside edit','folder_id'=>null,'revision'=>1])->assertForbidden();

        $this->actingAs($staff)->postJson('/folders',['source'=>'EMS','name'=>'Denied'])->assertForbidden();
        $this->postJson('/documents/batch',[])->assertForbidden();
        $this->postJson('/documents/'.$id.'/shares',['recipient_id'=>$admin->id,'permission'=>'Viewer'])->assertForbidden();
        $this->get('/documents/'.$id)->assertOk()->assertDontSee('Manage access')->assertSee('Upload new version');
        $this->get('/workspaces/ems')->assertOk()->assertSee('Upload document')->assertDontSee('New folder')->assertDontSee('Upload folder');
        $this->get('/admin')->assertForbidden();
    }

    public function test_staff_sign_in_redirects_to_assigned_workspace(): void
    {
        $staff=$this->staff(['role'=>'staff','source'=>'PMS','password'=>'SyntheticStaff!2026']);
        $this->post('/login',['email'=>$staff->email,'password'=>'SyntheticStaff!2026'])->assertRedirect('/workspaces/pms');
    }

    public function test_any_format_intake_covers_extensionless_binary_folder_and_api_files(): void
    {
        $user=$this->staff(['role'=>'admin','source'=>'EMS']);
        foreach (['README','archive.zip','audio.mp3','drawing.svg'] as $index=>$filename) {
            $this->actingAs($user)->postJson('/documents',['source'=>'EMS','classification'=>'Internal','title'=>$filename,'submission_key'=>'format-'.$index,'file'=>$this->file($filename,"Synthetic binary fixture\x00\x01")])->assertCreated()->assertJsonPath('scan_status','Pending');
        }
        $this->postJson('/documents/batch',['source'=>'EMS','classification'=>'Internal','submission_key'=>'any-format-folder','paths'=>['Archive/package.7z'],'files'=>[$this->file('package.7z','Synthetic archive fixture')]])->assertCreated()->assertJsonPath('results.0.scan_status','Pending');
        $this->withToken($this->token)->withHeader('Idempotency-Key','any-format-api')->postJson('/api/v1/documents',['title'=>'Binary evidence','classification'=>'Internal','file'=>$this->file('evidence.bin',"\x00\x01\x02")])->assertCreated()->assertJsonPath('scan_status','Pending');
        $this->assertDatabaseCount('documents',6);
        $this->assertDatabaseCount('document_versions',6);
        Queue::assertPushed(ScanDocumentVersion::class,6);
        foreach (DB::table('document_versions')->get() as $version) {
            $this->assertTrue(Storage::disk('local')->exists($version->storage_key));
            $this->assertStringStartsWith('documents/',$version->storage_key);
            $this->assertNotEmpty($version->mime);
        }
    }

    public function test_classification_changes_are_validated_revision_checked_and_owner_authorized(): void
    {
        $owner=$this->staff(['role'=>'staff']);
        $id=$this->upload($owner);
        $input=['title'=>'Classified file','folder_id'=>null,'revision'=>1,'classification'=>'Restricted'];
        $this->patch('/documents/'.$id,$input)->assertRedirect();
        $this->assertDatabaseHas('documents',['id'=>$id,'classification'=>'Restricted','revision'=>2]);
        $audit=DB::table('audit_entries')->where('action','document.updated')->where('document_id',$id)->first();
        $this->assertSame('Restricted',json_decode($audit->details,true)['classification_after']);
        $this->patchJson('/documents/'.$id,array_replace($input,['revision'=>2,'classification'=>'Public']))->assertUnprocessable();
        $this->patchJson('/documents/'.$id,array_replace($input,['classification'=>'Internal']))->assertConflict();
        $editor=$this->staff(['role'=>'staff']);
        DB::table('shares')->insert(['document_id'=>$id,'recipient_id'=>$editor->id,'permission'=>'Editor','created_at'=>now(),'updated_at'=>now()]);
        $this->actingAs($editor)->patchJson('/documents/'.$id,array_replace($input,['revision'=>2,'classification'=>'Internal']))->assertForbidden();
        $this->assertDatabaseHas('documents',['id'=>$id,'classification'=>'Restricted','revision'=>2]);
    }

    public function test_preview_remains_scan_scoped_pinned_and_never_serves_active_html(): void
    {
        $owner=$this->staff(['role'=>'staff']);
        $id=$this->upload($owner);
        $first=DB::table('document_versions')->where('document_id',$id)->first();
        $this->get('/files/'.$first->id.'/preview')->assertOk()->assertHeader('X-Content-Type-Options','nosniff');
        $this->get('/documents/'.$id)->assertOk()->assertSee('Document preview')->assertSee('id="document-preview-frame"',false)->assertSee('document-classification');
        $this->post('/documents/'.$id.'/versions',['source'=>'EMS','revision'=>1,'submission_key'=>'preview-v2','file'=>$this->file('second.txt','Version two')])->assertRedirect();
        $second=DB::table('document_versions')->where('document_id',$id)->where('number',2)->first();
        $this->get('/files/'.$second->id.'/preview')->assertStatus(423);
        DB::table('document_versions')->where('id',$second->id)->update(['scan_status'=>'Clean']);
        $viewer=$this->staff(['role'=>'staff']);
        DB::table('shares')->insert(['document_id'=>$id,'recipient_id'=>$viewer->id,'permission'=>'Viewer','version_id'=>$first->id,'created_at'=>now(),'updated_at'=>now()]);
        $this->actingAs($viewer)->get('/files/'.$first->id.'/preview')->assertOk();
        $this->get('/files/'.$second->id.'/preview')->assertForbidden();
        $this->get('/documents/'.$id)->assertOk()->assertDontSee('id="document-classification"',false);
        DB::table('document_versions')->where('id',$first->id)->update(['mime'=>'text/html']);
        $this->get('/files/'.$first->id.'/preview')->assertStatus(415);
        $other=$this->staff(['role'=>'staff']);
        $this->actingAs($other)->get('/files/'.$first->id.'/preview')->assertNotFound();
    }

    public function test_text_preview_escapes_html_instead_of_executing_it(): void
    {
        $owner=$this->staff(['role'=>'staff']);
        $id=$this->upload($owner,['file'=>$this->file('example.txt','Example: <script>alert(1)</script>')]);
        $version=DB::table('document_versions')->where('document_id',$id)->first();
        DB::table('document_versions')->where('id',$version->id)->update(['mime'=>'text/plain']);
        $this->get('/files/'.$version->id.'/preview')->assertOk()->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;',false)->assertDontSee('<script>alert(1)</script>',false);
    }

    public function test_trash_success_renders_as_a_top_right_toast_with_countdown(): void
    {
        $owner=$this->staff(['role'=>'staff']);
        $id=$this->upload($owner);
        $message='Document moved to trash. All versions are preserved.';
        $this->post('/documents/'.$id.'/trash',['revision'=>1])->assertRedirect()->assertSessionHas('status',$message);
        $this->get('/documents/'.$id)->assertOk()->assertSee($message)->assertSee('position-fixed top-0 end-0',false)->assertSee('toast-countdown',false)->assertDontSee('alert alert-success',false);
        $this->assertDatabaseCount('document_versions',1);
    }

    public function test_json_move_preserves_versions_and_checks_destination_revision_and_permissions(): void
    {
        $owner=$this->staff(['role'=>'staff']);
        $id=$this->upload($owner);
        $folder=(string) \Illuminate\Support\Str::uuid();
        DB::table('folders')->insert(['id'=>$folder,'owner_id'=>$owner->id,'name'=>'Allowed folder','source'=>'EMS','school_id'=>'PLP','campus'=>'Main','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $input=['title'=>'Synthetic staff file','folder_id'=>$folder,'revision'=>1];
        $this->patchJson('/documents/'.$id,$input)->assertOk()->assertJsonPath('folder_id',$folder)->assertJsonPath('revision',2);
        $this->assertDatabaseHas('documents',['id'=>$id,'folder_id'=>$folder,'revision'=>2]);
        $this->assertDatabaseCount('document_versions',1);
        $this->get('/workspaces/ems/documents')->assertOk()->assertViewHas('documents',fn($rows)=>$rows->count()===0);
        // Inside a folder, navigation out is the "Up to ..." tile rather than the
// global Back button, which is now reserved for the document preview.
$this->get('/workspaces/ems/documents?folder='.$folder)->assertOk()->assertSee('Synthetic staff file')->assertDontSee('data-global-back',false)->assertViewHas('folder',fn($row)=>$row->id===$folder)->assertViewHas('parentFolder',fn($row)=>is_null($row));
$this->get('/documents/'.$id)->assertOk()->assertSee('data-global-back',false);
        $this->patchJson('/documents/'.$id,array_replace($input,['folder_id'=>null]))->assertConflict();
        $other=$this->staff(['role'=>'staff']);
        DB::table('shares')->insert(['document_id'=>$id,'recipient_id'=>$other->id,'permission'=>'Editor','created_at'=>now(),'updated_at'=>now()]);
        $this->actingAs($other)->patchJson('/documents/'.$id,array_replace($input,['folder_id'=>null,'revision'=>2]))->assertForbidden();
        $this->actingAs($owner)->patchJson('/documents/'.$id,array_replace($input,['folder_id'=>null,'revision'=>2]))->assertOk()->assertJsonPath('folder_id',null);
        $badFolder=(string) \Illuminate\Support\Str::uuid();
        DB::table('folders')->insert(['id'=>$badFolder,'owner_id'=>$owner->id,'name'=>'Other source','source'=>'PMS','school_id'=>'PLP','campus'=>'Main','revision'=>1,'created_at'=>now(),'updated_at'=>now()]);
        $this->patchJson('/documents/'.$id,array_replace($input,['folder_id'=>$badFolder,'revision'=>3]))->assertForbidden();
        $this->assertDatabaseHas('documents',['id'=>$id,'folder_id'=>null,'revision'=>3]);
        $this->get('/workspaces/ems/documents')->assertOk()->assertSee('Move to folder')->assertSee('data-can-move="true"',false);
    }
}
