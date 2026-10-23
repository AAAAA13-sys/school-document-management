<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\Source;
use App\Jobs\ScanDocumentVersion;
use App\Models\User;
use App\Services\AccountChangeService;
use App\Services\Scanner;
use App\Services\SensitiveChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase5AccessControlTest extends TestCase
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
            'password' => 'password',
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
        $this->mock(Scanner::class, fn ($m) => $m->shouldReceive('scan')->andReturn('Clean'));
        $this->actingAs($user)->post('/documents', [
            'title' => $title,
            'source' => $source,
            'classification' => 'Internal',
            'submission_key' => (string) Str::uuid(),
            'file' => $this->file(Str::slug($title).'.txt', 'Phase 5 fixture '.$title),
        ])->assertRedirect();

        $document = DB::table('documents')->where('title', $title)->where('source', $source)->first();
        $this->assertNotNull($document);
        $version = DB::table('document_versions')->where('document_id', $document->id)->first();
        $this->assertNotNull($version);
        (new ScanDocumentVersion($version->id))->handle(app(Scanner::class));

        return [$document->id, $version->id];
    }

    public function test_suspended_user_is_blocked_on_next_request(): void
    {
        $staff = $this->account(['password' => 'password']);
        $admin = $this->account(['role' => Role::Admin->value]);
        [$document, $version] = $this->upload($staff, Source::EMS->value, 'Suspended download document');

        $this->actingAs($staff);
        $this->assertTrue($staff->fresh()->active);

        // Admin proposes suspension; approval changes live state and invalidates sessions.
        $this->actingAs($admin);
        $this->patch('/admin/accounts/'.$staff->id, [
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => Role::Staff->value,
            'source' => Source::EMS->value,
            'active' => 0,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($staff->fresh()->active);
        $change = DB::table('account_changes')->where('account_id', $staff->id)->where('status', 'pending')->value('id');
        app(AccountChangeService::class)->decide($this->account(['role' => Role::SuperAdmin->value]), $change, true, 'Approved suspension');
        $this->assertFalse($staff->fresh()->active);
        $this->assertNotNull($staff->fresh()->auth_version);

        // Reflect the suspension on the user object used by the next request.
        $staff = $staff->refresh();
        $this->actingAs($staff);

        // The next file download returns 403 while the session is still
        // authenticated but the account is now inactive; that blocked request
        // invalidates the session.
        $this->get('/files/'.$version)->assertForbidden();

        // Subsequent HTML requests are sent to sign in.
        $this->get('/workspaces/ems')->assertRedirect('/login');
        $this->get('/documents/'.$document)->assertRedirect('/login');
        $this->get('/account')->assertRedirect('/login');

        // JSON/API requests without a valid session are unauthenticated.
        $this->getJson('/workspaces/ems/documents')->assertStatus(401);
    }

    public function test_ems_staff_cannot_reach_other_sources_except_named_shares(): void
    {
        $emsStaff = $this->account();
        $pmsStaff = $this->account(['source' => Source::PMS->value, 'password' => 'password']);
        $pmsAdmin = $this->account(['role' => Role::Admin->value, 'source' => Source::PMS->value]);
        $esStaff = $this->account(['source' => Source::ES->value]);
        $oasStaff = $this->account(['source' => Source::OAS->value]);

        [$pmsDocument, $pmsVersion] = $this->upload($pmsStaff, Source::PMS->value, 'PMS private document');
        $this->upload($esStaff, Source::ES->value, 'ES private document');
        $this->upload($oasStaff, Source::OAS->value, 'OAS private document');

        // EMS staff cannot open foreign source workspaces at all.
        $this->actingAs($emsStaff)->get('/workspaces/pms/documents')->assertForbidden();
        $this->actingAs($emsStaff)->get('/workspaces/es/documents')->assertForbidden();
        $this->actingAs($emsStaff)->get('/workspaces/oas/documents')->assertForbidden();

        // Guessing IDs: view/download/edit/trash/move foreign documents.
        $this->actingAs($emsStaff)->get('/documents/'.$pmsDocument)->assertNotFound();
        $this->actingAs($emsStaff)->get('/files/'.$pmsVersion)->assertNotFound();
        $this->actingAs($emsStaff)->patch('/documents/'.$pmsDocument, [
            'title' => 'Tampered PMS', 'revision' => 1,
        ])->assertNotFound();
        $this->actingAs($emsStaff)->post('/documents/'.$pmsDocument.'/trash', ['revision' => 1])->assertNotFound();
        $this->actingAs($emsStaff)->patch('/documents/'.$pmsDocument, [
            'title' => 'Tampered PMS', 'folder_id' => null, 'revision' => 1,
        ])->assertNotFound();

        $this->actingAs($emsStaff)->get('/documents/'.DB::table('documents')->where('source', Source::ES->value)->value('id'))->assertNotFound();
        $this->actingAs($emsStaff)->get('/documents/'.DB::table('documents')->where('source', Source::OAS->value)->value('id'))->assertNotFound();

        // EMS staff cannot upload into or create folders in a foreign source.
        $this->actingAs($emsStaff)->post('/documents', [
            'title' => 'Cross-source upload', 'source' => Source::PMS->value,
            'classification' => 'Internal', 'submission_key' => (string) Str::uuid(),
            'file' => $this->file('cross-source.txt', 'Cross source bytes'),
        ])->assertForbidden();
        $this->actingAs($emsStaff)->post('/folders', [
            'source' => Source::PMS->value, 'name' => 'Foreign folder',
        ])->assertForbidden();

        // Staff cannot request access changes to documents they cannot see.
        // A guessed cross-workspace identifier remains undisclosed.
        $this->actingAs($emsStaff)->post('/documents/'.$pmsDocument.'/shares', [
            'recipient_id' => $emsStaff->id,
            'permission' => 'Viewer',
        ])->assertNotFound();

        // Explicit named share is the documented cross-source exception. Only an
        // Admin can create it, and the PMS Admin manages this source's documents.
        $this->actingAs($pmsAdmin)->post('/documents/'.$pmsDocument.'/shares', [
            'recipient_id' => $emsStaff->id,
            'permission' => 'Viewer', 'revision' => 1,
        ])->assertRedirect();
        $this->assertDatabaseMissing('shares', ['document_id' => $pmsDocument]);
        $approval = DB::table('sensitive_changes')->where('document_id', $pmsDocument)->where('status', 'pending')->value('id');
        app(SensitiveChangeService::class)->decide($this->account(['role' => Role::SuperAdmin->value]), $approval, true, 'Approve cross-workspace share');

        // EMS staff can now view and download the shared PMS document...
        $this->actingAs($emsStaff)->get('/documents/'.$pmsDocument)->assertOk();
        $this->actingAs($emsStaff)->get('/files/'.$pmsVersion)->assertOk();

        // ...but cannot edit, trash, reshare, or move it.
        $this->actingAs($emsStaff)->patch('/documents/'.$pmsDocument, ['title' => 'Escalated edit', 'revision' => 1])->assertForbidden();
        $this->actingAs($emsStaff)->post('/documents/'.$pmsDocument.'/trash', ['revision' => 2])->assertForbidden();
        $this->actingAs($emsStaff)->post('/documents/'.$pmsDocument.'/shares', [
            'recipient_id' => $this->account()->id,
            'permission' => 'Viewer', 'revision' => 2,
        ])->assertForbidden();
    }
}
