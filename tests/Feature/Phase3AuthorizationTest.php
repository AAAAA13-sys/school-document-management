<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\Source;
use App\Jobs\ScanDocumentVersion;
use App\Models\User;
use App\Services\AccountChangeService;
use App\Services\HierarchicalInvitationService as InvitationService;
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

class Phase3AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
    }

    private function account(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'role' => Role::Staff->value,
            'source' => Source::EMS->value,
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ]);
    }

    private function file(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }

    /**
     * @return array{0: string, 1: string} document id and version id
     */
    private function upload(User $user, string $source, string $title): array
    {
        $this->mock(Scanner::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn('Clean'));
        $this->actingAs($user)->post('/documents', [
            'title' => $title,
            'source' => $source,
            'classification' => 'Internal',
            'submission_key' => fake()->uuid(),
            'file' => $this->file(Str::slug($title).'.txt', 'Authorization fixture '.$title),
        ])->assertRedirect();

        $document = DB::table('documents')->where('title', $title)->where('source', $source)->first();
        $this->assertNotNull($document);
        $version = DB::table('document_versions')->where('document_id', $document->id)->first();
        $this->assertNotNull($version);
        (new ScanDocumentVersion($version->id))->handle(app(Scanner::class));

        return [$document->id, $version->id];
    }

    private function accountPayload(User $target, array $overrides = []): array
    {
        return $overrides + [
            'name' => $target->name,
            'email' => $target->email,
            'role' => $target->role,
            'source' => $target->source,
            'active' => $target->active ? 1 : 0,
        ];
    }

    private function createPayload(string $email, string $role, ?string $source): array
    {
        return [
            'name' => 'Phase Three Account',
            'email' => $email,
            'role' => $role,
            'source' => $source,
            'active' => 1,
        ];
    }

    public function test_super_admin_manages_scoped_documents_and_invites_accounts(): void
    {
        $super = $this->account(['role' => Role::SuperAdmin->value, 'source' => null]);
        $ems = $this->account();
        $pms = $this->account(['source' => Source::PMS->value]);
        [$emsDocument, $emsVersion] = $this->upload($ems, Source::EMS->value, 'Super EMS document');
        [$pmsDocument] = $this->upload($pms, Source::PMS->value, 'Super PMS document');

        $this->actingAs($super)->get('/documents/'.$emsDocument)->assertOk();
        $this->get('/documents/'.$pmsDocument)->assertOk();
        $this->get('/workspaces/ems/documents')->assertOk();
        $this->get('/workspaces/pms/documents')->assertOk();
        $this->get('/files/'.$emsVersion)->assertOk();

        $this->patch('/documents/'.$emsDocument, ['title' => 'Super Admin edited', 'revision' => 1])->assertRedirect();
        $this->post('/documents/'.$emsDocument.'/trash', ['revision' => 2])->assertRedirect();
        $this->assertSoftDeletedDocument($emsDocument);
        $this->post('/documents/'.$emsDocument.'/restore', ['revision' => 3])->assertRedirect();
        $this->assertNull(DB::table('documents')->where('id', $emsDocument)->value('trashed_at'));
        $this->post('/documents/'.$emsDocument.'/star')->assertRedirect();
        $this->post('/documents/'.$emsDocument.'/shares', ['recipient_id' => $ems->id, 'permission' => 'Viewer', 'revision' => 4])->assertRedirect();
        $this->post('/documents/'.$emsDocument.'/comments', ['body' => 'Scoped oversight'])->assertRedirect();
        $this->post('/folders', ['source' => Source::EMS->value, 'name' => 'Super folder'])->assertRedirect();

        config(['mail.default' => 'smtp']);
        Mail::fake();
        $email = 'phase3.new.admin@example.test';
        $this->post('/admin/accounts', $this->createPayload($email, Role::Admin->value, Source::PMS->value))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['email' => $email]);
        $invitation = DB::table('account_invitations')->where('email', $email)->first();
        $this->assertSame('sent', $invitation->status);
        $this->assertSame(Role::Admin->value, $invitation->role);
        // Only the recipient's valid activation token can create the account.
        $token = 'recipient-activation';
        DB::table('account_invitations')->where('id', $invitation->id)->update(['token_hash' => hash('sha256', $token)]);
        $created = app(InvitationService::class)->accept($token, 'RecipientPassword123!');
        $this->assertSame(Source::PMS->value, $created->source);

        $this->patch('/admin/accounts/'.$created->id, $this->accountPayload($created, ['active' => 0]))
            ->assertRedirect();
        $this->assertFalse($created->fresh()->active);
    }

    public function test_admin_manages_only_staff_and_content_in_its_own_source(): void
    {
        $admin = $this->account(['role' => Role::Admin->value]);
        $staff = $this->account();
        $otherStaff = $this->account(['source' => Source::PMS->value]);
        $otherAdmin = $this->account(['role' => Role::Admin->value]);
        $super = $this->account(['role' => Role::SuperAdmin->value, 'source' => null]);

        $staffEmail = 'phase3.staff@example.test';
        $this->actingAs($admin)->post('/admin/accounts', $this->createPayload($staffEmail, Role::Staff->value, Source::EMS->value) + [
            'school_id' => 'FORGED',
            'campus' => 'Elsewhere',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['email' => $staffEmail]);
        $invitation = DB::table('account_invitations')->where('email', $staffEmail)->first();
        $this->assertSame('pending', $invitation->status);
        $this->assertSame('PLP', $invitation->school_id);
        $this->assertSame('Main', $invitation->campus);
        app(InvitationService::class)->review($super, $invitation->id, true, 'Approve scoped staff');
        $this->assertDatabaseHas('account_invitations', ['id' => $invitation->id, 'status' => 'approved']);

        $this->post('/admin/accounts', $this->createPayload('phase3.staff.pms@example.test', Role::Staff->value, Source::PMS->value))->assertForbidden();
        $this->post('/admin/accounts', $this->createPayload('phase3.admin.ems@example.test', Role::Admin->value, Source::EMS->value))->assertForbidden();
        $this->post('/admin/accounts', $this->createPayload('phase3.super@example.test', Role::SuperAdmin->value, null))->assertForbidden();

        $this->patch('/admin/accounts/'.$otherAdmin->id, $this->accountPayload($otherAdmin))->assertForbidden();
        $this->patch('/admin/accounts/'.$super->id, $this->accountPayload($super))->assertNotFound();
        $this->patch('/admin/accounts/'.$otherStaff->id, $this->accountPayload($otherStaff))->assertNotFound();

        $this->patch('/admin/accounts/'.$staff->id, $this->accountPayload($staff, ['active' => 0]))->assertRedirect();
        $this->assertTrue($staff->fresh()->active);
        $change = DB::table('account_changes')->where('account_id', $staff->id)->where('status', 'pending')->value('id');
        app(AccountChangeService::class)->decide($super, $change, true, 'Approve suspension');
        $this->assertFalse($staff->fresh()->active);
        $this->patch('/admin/accounts/'.$staff->id, $this->accountPayload($staff, ['active' => 1]))->assertRedirect();
        $this->assertFalse($staff->fresh()->active);
        $change = DB::table('account_changes')->where('account_id', $staff->id)->where('status', 'pending')->value('id');
        app(AccountChangeService::class)->decide($super, $change, true, 'Approve reactivation');
        $this->assertTrue($staff->fresh()->active);

        $this->get('/admin/accounts')->assertOk()->assertDontSee($otherStaff->email);
        $this->get('/admin/connectors')->assertForbidden();
        $this->get('/workspaces/pms')->assertForbidden();
    }

    public function test_staff_can_read_source_documents_but_only_edit_or_delete_their_own(): void
    {
        $owner = $this->account();
        $viewer = $this->account();
        [$document, $version] = $this->upload($owner, Source::EMS->value, 'Staff source document');

        $this->actingAs($viewer)->get('/documents/'.$document)->assertOk();
        $this->get('/files/'.$version)->assertOk();
        $this->patch('/documents/'.$document, ['title' => 'Tampered', 'revision' => 1])->assertForbidden();
        $this->post('/documents/'.$document.'/trash', ['revision' => 1])->assertForbidden();
        $this->post('/documents/'.$document.'/comments', ['body' => 'Not mine'])->assertForbidden();
        $this->post('/documents/'.$document.'/shares', ['recipient_id' => $owner->id, 'permission' => 'Viewer'])->assertForbidden();
        $this->post('/folders', ['source' => Source::EMS->value, 'name' => 'Staff folder'])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/documents/batch', ['source' => Source::EMS->value])->assertSessionHasErrors(['files', 'paths']);
        $this->post('/documents', [
            'title' => 'Cross-source upload',
            'source' => Source::PMS->value,
            'classification' => 'Internal',
            'submission_key' => 'phase3-cross-source-upload',
            'file' => $this->file('cross-source.txt', 'Cross source bytes'),
        ])->assertForbidden();
        $this->get('/admin')->assertForbidden();
        $this->get('/admin/audit')->assertForbidden();
        $this->get('/workspaces/pms')->assertForbidden();

        DB::table('workspaces')->insert(['school_id' => 'PLP', 'campus' => 'Other', 'code' => 'EMS', 'name' => 'Other campus EMS', 'kind' => 'erp', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $outsideOwner = $this->account(['campus' => 'Other']);
        [$outsideDocument] = $this->upload($outsideOwner, Source::EMS->value, 'Other campus document');
        $this->actingAs($viewer)->get('/documents/'.$outsideDocument)->assertNotFound();

        $this->actingAs($owner)->patch('/documents/'.$document, ['title' => 'Staff edited', 'revision' => 1])->assertRedirect();
        $this->post('/documents/'.$document.'/trash', ['revision' => 2])->assertRedirect();
        $this->assertNull(DB::table('documents')->where('id', $document)->value('trashed_at'));
        $approval = DB::table('sensitive_changes')->where('document_id', $document)->where('status', 'pending')->value('id');
        app(SensitiveChangeService::class)->decide($this->account(['role' => Role::Admin->value]), $approval, true, 'Approve owner trash');
        $this->assertSoftDeletedDocument($document);
    }

    public function test_admin_document_and_folder_management_stops_at_its_source(): void
    {
        $admin = $this->account(['role' => Role::Admin->value]);
        $emsStaff = $this->account();
        $pmsAdmin = $this->account(['role' => Role::Admin->value, 'source' => Source::PMS->value]);
        $pmsStaff = $this->account(['source' => Source::PMS->value]);
        [$ownDocument] = $this->upload($emsStaff, Source::EMS->value, 'Admin own document');
        [$otherDocument] = $this->upload($pmsStaff, Source::PMS->value, 'Admin other document');

        $this->actingAs($admin)->get('/documents/'.$ownDocument)->assertOk();
        $this->patch('/documents/'.$ownDocument, ['title' => 'Admin edited', 'revision' => 1])->assertRedirect();
        $this->get('/documents/'.$otherDocument)->assertNotFound();
        $this->patch('/documents/'.$otherDocument, ['title' => 'Not mine', 'revision' => 1])->assertNotFound();
        $this->post('/documents/'.$otherDocument.'/trash', ['revision' => 1])->assertNotFound();

        $this->actingAs($pmsAdmin)->post('/folders', ['source' => Source::PMS->value, 'name' => 'PMS folder'])->assertRedirect();
        $pmsFolder = DB::table('folders')->where('name', 'PMS folder')->value('id');
        $this->post('/documents/'.$otherDocument.'/shares', ['recipient_id' => $admin->id, 'permission' => 'Editor', 'revision' => 1])->assertRedirect();
        $this->assertDatabaseMissing('shares', ['document_id' => $otherDocument]);
        $approval = DB::table('sensitive_changes')->where('document_id', $otherDocument)->where('status', 'pending')->value('id');
        app(SensitiveChangeService::class)->decide($this->account(['role' => Role::SuperAdmin->value]), $approval, true, 'Approve cross-workspace share');

        // A named share is the cross-source exception, but it is not management authority.
        $this->actingAs($admin)->get('/documents/'.$otherDocument)->assertOk();
        $this->patchJson('/documents/'.$ownDocument, ['title' => 'Admin edited', 'folder_id' => $pmsFolder, 'revision' => 2])->assertNotFound();
        $this->patch('/documents/'.$otherDocument, ['title' => 'Shared edit', 'revision' => 1])->assertForbidden();
        $this->post('/documents/'.$otherDocument.'/trash', ['revision' => 2])->assertForbidden();
        $this->post('/documents/'.$otherDocument.'/shares', ['recipient_id' => $emsStaff->id, 'permission' => 'Viewer', 'revision' => 2])->assertForbidden();

        $this->patch('/folders/'.$pmsFolder, ['name' => 'Changed', 'revision' => 1])->assertNotFound();
        $this->post('/folders', ['source' => Source::PMS->value, 'name' => 'Bad folder'])->assertForbidden();
        $this->post('/folders', ['source' => Source::EMS->value, 'name' => 'Good folder'])->assertRedirect();
    }

    public function test_staff_cannot_tamper_with_account_fields_through_profile_requests(): void
    {
        $staff = $this->account(['password' => 'password']);

        $this->actingAs($staff)->patch('/admin/accounts/'.$staff->id, [
            'name' => 'Escalated',
            'email' => $staff->email,
            'role' => Role::SuperAdmin->value,
            'source' => Source::PMS->value,
            'active' => 0,
        ])->assertForbidden();

        $this->post('/documents', [
            'title' => 'Tamper upload',
            'source' => Source::EMS->value,
            'classification' => 'Internal',
            'submission_key' => 'phase3-tamper-upload',
            'file' => $this->file('tamper.txt', 'Tamper bytes'),
            'role' => Role::SuperAdmin->value,
            'school_id' => 'FORGED',
            'campus' => 'Other',
            'active' => 0,
        ])->assertRedirect();

        $staff->refresh();
        $this->assertSame(Role::Staff->value, $staff->role);
        $this->assertSame(Source::EMS->value, $staff->source);
        $this->assertSame('PLP', $staff->school_id);
        $this->assertSame('Main', $staff->campus);
        $this->assertTrue($staff->active);
        $document = DB::table('documents')->where('title', 'Tamper upload')->firstOrFail();
        $this->assertSame($staff->id, $document->owner_id);
        $this->assertSame('PLP', $document->school_id);
        $this->assertSame('Main', $document->campus);
        $this->assertSame(Source::EMS->value, $document->source);

        $this->put('/account/password', [
            'current_password' => 'password',
            'password' => 'NewSynthetic!123',
            'password_confirmation' => 'NewSynthetic!123',
            'role' => Role::SuperAdmin->value,
            'source' => Source::PMS->value,
            'school_id' => 'FORGED',
            'campus' => 'Other',
            'active' => 0,
        ])->assertRedirect();

        $staff->refresh();
        $this->assertSame(Role::Staff->value, $staff->role);
        $this->assertSame(Source::EMS->value, $staff->source);
        $this->assertSame('PLP', $staff->school_id);
        $this->assertSame('Main', $staff->campus);
        $this->assertTrue($staff->active);
        $this->assertTrue(Hash::check('NewSynthetic!123', $staff->password));
    }

    public function test_inactive_accounts_redirect_html_and_return_forbidden_for_machine_requests(): void
    {
        $owner = $this->account();
        [$document, $version] = $this->upload($owner, Source::EMS->value, 'Inactive download document');
        $inactive = $this->account(['active' => false]);

        $this->actingAs($inactive)->get('/workspaces/ems')->assertRedirect('/login');
        $this->actingAs($inactive)->get('/documents/'.$document)->assertRedirect('/login');
        $this->actingAs($inactive)->getJson('/workspaces/ems')->assertForbidden();
        $this->actingAs($inactive)->postJson('/documents', ['source' => Source::EMS->value])->assertForbidden();
        $this->actingAs($inactive)->get('/files/'.$version)
            ->assertForbidden()
            ->assertJsonPath('message', 'Your account is inactive. Contact your administrator.');
    }

    public function test_admin_audit_log_is_source_scoped_and_staff_cannot_open_it(): void
    {
        $admin = $this->account(['role' => Role::Admin->value]);
        $staff = $this->account();
        $super = $this->account(['role' => Role::SuperAdmin->value, 'source' => null]);

        foreach ([[Source::EMS->value, 'EMS_AUDIT_MARKER'], [Source::PMS->value, 'PMS_AUDIT_MARKER']] as [$source, $action]) {
            DB::table('audit_entries')->insert([
                'school_id' => 'PLP',
                'campus' => 'Main',
                'source' => $source,
                'action' => $action,
                'details' => json_encode([]),
                'created_at' => now(),
            ]);
        }

        $this->actingAs($admin)->get('/admin/audit')->assertOk()
            ->assertSee('EMS_AUDIT_MARKER')->assertDontSee('PMS_AUDIT_MARKER');
        $this->actingAs($super)->get('/admin/audit')->assertOk()
            ->assertSee('EMS_AUDIT_MARKER')->assertSee('PMS_AUDIT_MARKER');
        $this->actingAs($staff)->get('/admin/audit')->assertForbidden();
    }

    public function test_dms_is_rejected_as_a_source_and_workspace(): void
    {
        $admin = $this->account(['role' => Role::Admin->value]);
        $staff = $this->account();

        $this->actingAs($staff)->post('/documents', [
            'title' => 'DMS upload',
            'source' => 'DMS',
            'classification' => 'Internal',
            'submission_key' => 'phase3-dms-upload',
            'file' => $this->file('dms.txt', 'DMS bytes'),
        ])->assertSessionHasErrors('source');

        $this->actingAs($admin)->post('/folders', ['source' => 'DMS', 'name' => 'DMS folder'])
            ->assertSessionHasErrors('source');
        $this->post('/documents/batch', [
            'source' => 'DMS',
            'classification' => 'Internal',
            'submission_key' => 'phase3-dms-batch',
            'paths' => ['DMS folder/batch.txt'],
            'files' => [$this->file('batch.txt', 'DMS batch bytes')],
        ])->assertSessionHasErrors('source');

        $this->actingAs($staff)->get('/workspaces/dms')->assertNotFound();
        $this->assertNotContains('DMS', Source::values());
        $this->assertDatabaseMissing('documents', ['source' => 'DMS']);
    }

    private function assertSoftDeletedDocument(string $document): void
    {
        $this->assertNotNull(DB::table('documents')->where('id', $document)->value('trashed_at'));
    }
}
