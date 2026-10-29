<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\Source;
use App\Jobs\ScanDocumentVersion;
use App\Models\User;
use App\Services\Access;
use App\Services\DuplicateDetection;
use App\Services\Scanner;
use App\Services\SensitiveChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase4ClassificationTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'phase-4-connector-token';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->mock(Scanner::class, fn ($mock) => $mock->shouldReceive('scan')->andReturn('Clean'));
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
    private function upload(
        User $user,
        string $classification,
        string $title,
        string $source = Source::EMS->value,
        ?string $folderId = null,
        string $content = 'Phase 4 document contents',
    ): array {
        $this->actingAs($user)->post('/documents', [
            'title' => $title,
            'source' => $source,
            'classification' => $classification,
            'folder_id' => $folderId,
            'submission_key' => (string) Str::uuid(),
            'confirm_duplicate' => 1,
            'file' => $this->file(Str::slug($title).'.txt', $content),
        ])->assertRedirect();

        $document = DB::table('documents')->where('title', $title)->where('source', $source)->first();
        $this->assertNotNull($document);
        $version = DB::table('document_versions')->where('document_id', $document->id)->first();
        $this->assertNotNull($version);
        (new ScanDocumentVersion($version->id))->handle(app(Scanner::class));

        return [$document->id, $version->id];
    }

    private function insertDocument(
        User $owner,
        string $classification,
        string $title,
        ?string $folderId = null,
        string $source = Source::EMS->value,
    ): string {
        $id = (string) Str::uuid();
        DB::table('documents')->insert([
            'id' => $id,
            'owner_id' => $owner->id,
            'folder_id' => $folderId,
            'source' => $source,
            'school_id' => $owner->school_id,
            'campus' => $owner->campus,
            'title' => $title,
            'classification' => $classification,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function listedIds($response): array
    {
        return collect($response->viewData('documents')->items())->pluck('id')->all();
    }

    private function revision(string $document): int
    {
        return (int) DB::table('documents')->where('id', $document)->value('revision');
    }

    private function assertWorkspaceCount(User $user, string $source, int $expected): void
    {
        $this->actingAs($user)->get('/workspaces/'.strtolower($source))
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats) => (int) $stats['documents'] === $expected);
    }

    private function assertAdminCount(User $user, int $expected): void
    {
        $this->actingAs($user)->get('/admin/overview')
            ->assertOk()
            ->assertViewHas('stats', fn (array $stats) => (int) $stats['documents'] === $expected);
    }

    private function assertWorkspaceSearch(User $user, string $source, string $term, string $document, bool $expected): void
    {
        $response = $this->actingAs($user)
            ->get('/workspaces/'.strtolower($source).'/documents?q='.urlencode($term))
            ->assertOk();
        $this->assertSame($expected, in_array($document, $this->listedIds($response), true));
    }

    public function test_internal_visibility_matrix_and_hidden_id_leaks(): void
    {
        $owner = $this->account();
        $staff = $this->account();
        $admin = $this->account(['role' => Role::Admin->value]);
        $otherAdmin = $this->account(['role' => Role::Admin->value, 'source' => Source::PMS->value]);
        $super = $this->account(['role' => Role::SuperAdmin->value, 'source' => null]);
        $inactive = $this->account(['active' => false]);

        [$document, $version] = $this->upload($owner, 'Internal', 'Internal visibility');

        foreach ([$owner, $staff, $admin, $super] as $viewer) {
            $this->actingAs($viewer)->get('/documents/'.$document)->assertOk();
            $this->actingAs($viewer)->get('/files/'.$version)->assertOk();
        }

        $this->actingAs($otherAdmin)->get('/documents/'.$document)->assertNotFound();
        $this->actingAs($otherAdmin)->get('/files/'.$version)->assertNotFound();
        $this->actingAs($otherAdmin)->get('/documents/'.Str::uuid())->assertNotFound();
        $this->actingAs($otherAdmin)->get('/files/'.Str::uuid())->assertNotFound();

        $listing = $this->actingAs($staff)->get('/workspaces/ems/documents')->assertOk();
        $this->assertContains($document, $this->listedIds($listing));
        $this->assertWorkspaceSearch($staff, 'EMS', 'Internal visibility', $document, true);
        $this->assertWorkspaceCount($staff, 'EMS', 1);
        $this->assertWorkspaceCount($admin, 'EMS', 1);
        $this->assertWorkspaceCount($otherAdmin, 'PMS', 0);
        $this->assertWorkspaceCount($super, 'EMS', 1);
        $this->assertAdminCount($admin, 1);
        $this->assertAdminCount($otherAdmin, 0);
        $this->assertAdminCount($super, 1);

        $this->actingAs($otherAdmin)->get('/admin/repository?q=Internal+visibility')
            ->assertOk()
            ->assertViewHas('documents', fn ($documents) => ! collect($documents->items())->pluck('id')->contains($document));

        $this->assertSame(0, Access::documents($inactive)->count());
        $this->assertFalse($inactive->can('document.view', DB::table('documents')->where('id', $document)->first()));
    }

    public function test_confidential_visibility_and_explicit_shares(): void
    {
        $owner = $this->account();
        $staff = $this->account();
        $admin = $this->account(['role' => Role::Admin->value]);
        $otherAdmin = $this->account(['role' => Role::Admin->value, 'source' => Source::PMS->value]);
        $super = $this->account(['role' => Role::SuperAdmin->value, 'source' => null]);

        [$document, $version] = $this->upload($owner, 'Confidential', 'Confidential visibility');

        foreach ([$owner, $admin, $super] as $viewer) {
            $this->actingAs($viewer)->get('/documents/'.$document)->assertOk();
            $this->actingAs($viewer)->get('/files/'.$version)->assertOk();
        }
        foreach ([$staff, $otherAdmin] as $viewer) {
            $this->actingAs($viewer)->get('/documents/'.$document)->assertNotFound();
            $this->actingAs($viewer)->get('/files/'.$version)->assertNotFound();
        }

        $this->assertWorkspaceSearch($staff, 'EMS', 'Confidential visibility', $document, false);
        $this->assertWorkspaceSearch($admin, 'EMS', 'Confidential visibility', $document, true);
        $this->assertWorkspaceCount($staff, 'EMS', 0);
        $this->assertWorkspaceCount($admin, 'EMS', 1);
        $this->assertWorkspaceCount($otherAdmin, 'PMS', 0);
        $this->assertWorkspaceCount($super, 'EMS', 1);
        $this->assertAdminCount($otherAdmin, 0);

        $this->actingAs($admin)->post('/documents/'.$document.'/shares', [
            'revision' => $this->revision($document),
            'recipient_id' => $staff->id,
            'permission' => 'Viewer',
        ])->assertRedirect();
        $this->approvePending();

        $this->actingAs($staff)->get('/documents/'.$document)->assertOk();
        $this->actingAs($staff)->get('/files/'.$version)->assertOk();
        $this->actingAs($staff)->get('/workspaces/ems/documents?view=shared')
            ->assertOk()
            ->assertViewHas('documents', fn ($documents) => collect($documents->items())->pluck('id')->contains($document));
        $this->assertWorkspaceCount($staff, 'EMS', 1);

        // A cross-source named share is the documented read-only exception.
        $this->actingAs($admin)->post('/documents/'.$document.'/shares', [
            'revision' => $this->revision($document),
            'recipient_id' => $otherAdmin->id,
            'permission' => 'Viewer',
        ])->assertRedirect();
        $this->approvePending();
        $this->actingAs($otherAdmin)->get('/documents/'.$document)->assertOk();
        $this->actingAs($otherAdmin)->get('/files/'.$version)->assertOk();
        $this->actingAs($otherAdmin)->post('/documents/'.$document.'/trash', ['revision' => $this->revision($document)])->assertForbidden();
        $this->actingAs($otherAdmin)->post('/documents/'.$document.'/shares', [
            'revision' => $this->revision($document),
            'recipient_id' => $staff->id,
            'permission' => 'Editor',
        ])->assertForbidden();

        // Super Admin can directly share Confidential documents.
        $this->actingAs($super)->post('/documents/'.$document.'/shares', [
            'revision' => $this->revision($document),
            'recipient_id' => $staff->id,
            'permission' => 'Viewer',
        ])->assertRedirect();
    }

    public function test_restricted_visibility_and_super_admin_sharing(): void
    {
        $owner = $this->account();
        $staff = $this->account();
        $admin = $this->account(['role' => Role::Admin->value]);
        $otherAdmin = $this->account(['role' => Role::Admin->value, 'source' => Source::PMS->value]);
        $super = $this->account(['role' => Role::SuperAdmin->value, 'source' => null]);

        [$document, $version] = $this->upload($owner, 'Restricted', 'Restricted visibility');

        foreach ([$owner, $super] as $viewer) {
            $this->actingAs($viewer)->get('/documents/'.$document)->assertOk();
            $this->actingAs($viewer)->get('/files/'.$version)->assertOk();
        }
        foreach ([$staff, $admin, $otherAdmin] as $viewer) {
            $this->actingAs($viewer)->get('/documents/'.$document)->assertNotFound();
            $this->actingAs($viewer)->get('/files/'.$version)->assertNotFound();
        }

        $this->assertWorkspaceSearch($staff, 'EMS', 'Restricted visibility', $document, false);
        $this->assertWorkspaceSearch($admin, 'EMS', 'Restricted visibility', $document, false);
        $this->assertWorkspaceSearch($super, 'EMS', 'Restricted visibility', $document, true);
        $this->assertWorkspaceCount($staff, 'EMS', 0);
        $this->assertWorkspaceCount($admin, 'EMS', 0);
        $this->assertWorkspaceCount($otherAdmin, 'PMS', 0);
        $this->assertWorkspaceCount($super, 'EMS', 1);
        $this->assertAdminCount($admin, 0);
        $this->assertAdminCount($super, 1);
        $this->actingAs($admin)->get('/admin/audit')
            ->assertOk()
            ->assertViewHas('audits', fn ($audits) => ! collect($audits->items())->pluck('document_id')->contains($document));
        $this->actingAs($super)->get('/admin/audit')
            ->assertOk()
            ->assertViewHas('audits', fn ($audits) => collect($audits->items())->pluck('document_id')->contains($document));

        // Staff and non-owner Admins cannot share Restricted documents. A hidden
        // document is a 404 before the policy can even be reached.
        $this->actingAs($owner)->post('/documents/'.$document.'/shares', [
            'revision' => $this->revision($document),
            'recipient_id' => $staff->id,
            'permission' => 'Viewer',
        ])->assertForbidden();
        $this->actingAs($admin)->post('/documents/'.$document.'/shares', [
            'revision' => $this->revision($document),
            'recipient_id' => $staff->id,
            'permission' => 'Viewer',
        ])->assertNotFound();

        // Super Admin can share Restricted documents, but not Internal/Confidential.
        $this->actingAs($super)->get('/documents/'.$document)
            ->assertOk()
            ->assertViewHas('sharer', true)
            ->assertViewHas('recipients', fn ($recipients) => $recipients->isNotEmpty());
        $this->actingAs($super)->post('/documents/'.$document.'/shares', [
            'revision' => $this->revision($document),
            'recipient_id' => $staff->id,
            'permission' => 'Viewer',
        ])->assertRedirect();
        $this->approvePending();
        $this->actingAs($super)->post('/documents/'.$document.'/shares', [
            'revision' => $this->revision($document),
            'recipient_id' => $otherAdmin->id,
            'permission' => 'Editor',
        ])->assertRedirect();
        $this->approvePending();
        $this->actingAs($super)->post('/documents/'.$document.'/shares', [
            'revision' => $this->revision($document),
            'recipient_id' => $admin->id,
            'permission' => 'Editor',
        ])->assertRedirect();
        $this->approvePending();

        $this->actingAs($staff)->get('/documents/'.$document)->assertOk();
        $this->actingAs($staff)->get('/files/'.$version)->assertOk();
        $this->assertWorkspaceCount($staff, 'EMS', 1);
        $this->actingAs($otherAdmin)->get('/documents/'.$document)->assertOk();
        $this->actingAs($otherAdmin)->get('/files/'.$version)->assertOk();
        $this->actingAs($otherAdmin)->patch('/documents/'.$document, [
            'title' => 'Cross-source restricted edit',
            'revision' => $this->revision($document),
        ])->assertForbidden();
        $this->actingAs($otherAdmin)->post('/documents/'.$document.'/trash', ['revision' => $this->revision($document)])->assertForbidden();
        $this->actingAs($otherAdmin)->post('/documents/'.$document.'/shares', [
            'revision' => $this->revision($document),
            'recipient_id' => $staff->id,
            'permission' => 'Editor',
        ])->assertForbidden();

        // A same-source Admin Editor share can edit content but still cannot
        // manage or re-share the Restricted document.
        $this->actingAs($admin)->get('/documents/'.$document)->assertOk();
        $this->actingAs($admin)->patch('/documents/'.$document, [
            'title' => 'Same-source restricted edit',
            'revision' => $this->revision($document),
        ])->assertRedirect();
        $this->approvePending();
        $this->actingAs($admin)->post('/documents/'.$document.'/trash', ['revision' => $this->revision($document)])->assertForbidden();
        $this->actingAs($admin)->post('/documents/'.$document.'/shares', [
            'revision' => $this->revision($document),
            'recipient_id' => $staff->id,
            'permission' => 'Viewer',
        ])->assertForbidden();

        // An Admin who owns a Restricted document still cannot share it.
        [$adminDocument] = $this->upload($admin, 'Restricted', 'Admin restricted');
        $this->actingAs($admin)->post('/documents/'.$adminDocument.'/shares', [
            'revision' => $this->revision($adminDocument),
            'recipient_id' => $staff->id,
            'permission' => 'Viewer',
        ])->assertForbidden();

        // Latest SDD grants Super Admin full document authority.
        $this->actingAs($super)->patch('/documents/'.$document, [
            'title' => 'Super edit attempt',
            'classification' => 'Internal',
            'revision' => $this->revision($document),
        ])->assertRedirect();
        $this->actingAs($super)->post('/documents/'.$document.'/trash', ['revision' => $this->revision($document)])->assertRedirect();
        $this->actingAs($super)->post('/documents', ['source' => Source::EMS->value])->assertSessionHasErrors(['file', 'title']);

        [$confidential] = $this->upload($owner, 'Confidential', 'Super can share confidential');
        $this->actingAs($super)->post('/documents/'.$confidential.'/shares', [
            'revision' => $this->revision($confidential),
            'recipient_id' => $staff->id,
            'permission' => 'Viewer',
        ])->assertRedirect();
    }

    public function test_classification_changes_follow_visibility_and_management_authority(): void
    {
        $owner = $this->account();
        $staff = $this->account();
        $admin = $this->account(['role' => Role::Admin->value]);
        $super = $this->account(['role' => Role::SuperAdmin->value, 'source' => null]);

        [$document] = $this->upload($owner, 'Internal', 'Classification lifecycle');

        // An Admin can move an Internal document to Confidential but cannot
        // promote a non-owned document to Restricted.
        $this->actingAs($admin)->patch('/documents/'.$document, [
            'title' => DB::table('documents')->where('id', $document)->value('title'),
            'classification' => 'Restricted',
            'revision' => $this->revision($document),
        ])->assertForbidden();
        $this->actingAs($admin)->patch('/documents/'.$document, [
            'title' => DB::table('documents')->where('id', $document)->value('title'),
            'classification' => 'Confidential',
            'revision' => $this->revision($document),
        ])->assertRedirect();
        $this->approvePending();

        $this->actingAs($staff)->get('/documents/'.$document)->assertNotFound();
        $this->actingAs($admin)->get('/documents/'.$document)->assertOk();
        $this->actingAs($super)->get('/documents/'.$document)->assertOk();

        // An Editor share permits content edits but not classification changes.
        $this->actingAs($admin)->post('/documents/'.$document.'/shares', [
            'revision' => $this->revision($document),
            'recipient_id' => $staff->id,
            'permission' => 'Editor',
        ])->assertRedirect();
        $this->approvePending();
        $this->actingAs($staff)->patch('/documents/'.$document, [
            'title' => 'Editor title change',
            'revision' => $this->revision($document),
        ])->assertRedirect();
        $this->approvePending();
        $this->actingAs($staff)->patch('/documents/'.$document, [
            'title' => 'Editor title change',
            'classification' => 'Internal',
            'revision' => $this->revision($document),
        ])->assertForbidden();

        $this->actingAs($admin)->patch('/documents/'.$document, [
            'title' => DB::table('documents')->where('id', $document)->value('title'),
            'classification' => 'Restricted',
            'revision' => $this->revision($document),
        ])->assertForbidden();

        // The owner can raise and lower their own document. An existing Editor
        // share still works at the Restricted level.
        $this->actingAs($owner)->patch('/documents/'.$document, [
            'title' => DB::table('documents')->where('id', $document)->value('title'),
            'classification' => 'Restricted',
            'revision' => $this->revision($document),
        ])->assertRedirect();
        $this->approvePending();
        $this->actingAs($admin)->get('/documents/'.$document)->assertNotFound();
        $this->actingAs($staff)->get('/documents/'.$document)->assertOk();
        $this->actingAs($super)->get('/documents/'.$document)->assertOk();

        $this->actingAs($owner)->patch('/documents/'.$document, [
            'title' => DB::table('documents')->where('id', $document)->value('title'),
            'classification' => 'Internal',
            'revision' => $this->revision($document),
        ])->assertRedirect();
        $this->approvePending();
        $this->actingAs($admin)->get('/documents/'.$document)->assertOk();

        // Super Admin applies classification changes directly.
        $this->actingAs($super)->patch('/documents/'.$document, [
            'title' => DB::table('documents')->where('id', $document)->value('title'),
            'classification' => 'Confidential',
            'revision' => $this->revision($document),
        ])->assertRedirect();

        // An Admin owner can lower their own Restricted document.
        [$adminDocument] = $this->upload($admin, 'Restricted', 'Admin classification lifecycle');
        $this->actingAs($admin)->patch('/documents/'.$adminDocument, [
            'title' => 'Admin classification lifecycle',
            'classification' => 'Internal',
            'revision' => $this->revision($adminDocument),
        ])->assertRedirect();
        $this->approvePending();
        $this->actingAs($staff)->get('/documents/'.$adminDocument)->assertOk();
    }

    public function test_folder_grants_and_totals_respect_classification(): void
    {
        $admin = $this->account(['role' => Role::Admin->value]);
        $owner = $this->account();
        $pmsStaff = $this->account(['source' => Source::PMS->value]);

        $this->actingAs($admin)->post('/folders', [
            'source' => Source::EMS->value,
            'name' => 'Phase 4 classification folder',
        ])->assertRedirect();
        $folder = DB::table('folders')->where('name', 'Phase 4 classification folder')->first();
        $this->assertNotNull($folder);

        [$internal] = $this->upload($admin, 'Internal', 'Folder internal', Source::EMS->value, $folder->id);
        [$confidential] = $this->upload($admin, 'Confidential', 'Folder confidential', Source::EMS->value, $folder->id);
        $restricted = $this->insertDocument($owner, 'Restricted', 'Folder restricted', $folder->id);

        $this->actingAs($admin)->post('/folders/'.$folder->id.'/shares', [
            'recipient_id' => $pmsStaff->id, 'permission' => 'Viewer',
        ])->assertNotFound();
        // Existing historical grants remain readable; new folder sharing is excluded.
        DB::table('folder_shares')->insert(['folder_id' => $folder->id, 'recipient_id' => $pmsStaff->id,
            'permission' => 'Viewer', 'created_at' => now(), 'updated_at' => now()]);

        // Admin sees Internal and Confidential in the folder, but not the
        // Restricted document owned by Staff.
        $this->actingAs($admin)->get('/workspaces/ems/documents?folder='.$folder->id)
            ->assertOk()
            ->assertViewHas('folders', function ($folders) use ($folder) {
                $row = collect($folders)->firstWhere('id', $folder->id);

                return $row && (int) $row->document_count === 2;
            });

        // A folder grant only widens Internal documents; it cannot bypass
        // Confidential or Restricted document-level sharing.
        $this->actingAs($pmsStaff)->get('/documents/'.$internal)->assertOk();
        $this->actingAs($pmsStaff)->get('/documents/'.$confidential)->assertNotFound();
        $this->actingAs($pmsStaff)->get('/documents/'.$restricted)->assertNotFound();

        $shared = $this->actingAs($pmsStaff)->get('/workspaces/pms/documents?view=shared')->assertOk();
        $this->assertContains($internal, $this->listedIds($shared));
        $this->assertNotContains($confidential, $this->listedIds($shared));
        $this->assertNotContains($restricted, $this->listedIds($shared));
        $this->assertSame(1, Access::documents($pmsStaff)->where('folder_id', $folder->id)->count());
        $this->assertTrue(Access::folders($pmsStaff)->where('id', $folder->id)->exists());

        // Explicit document shares still cross the classification boundary.
        $this->actingAs($admin)->post('/documents/'.$confidential.'/shares', [
            'revision' => $this->revision($confidential),
            'recipient_id' => $pmsStaff->id,
            'permission' => 'Viewer',
        ])->assertRedirect();
        $this->approvePending();
        $this->actingAs($pmsStaff)->get('/documents/'.$confidential)->assertOk();
        $this->assertSame(2, Access::documents($pmsStaff)->where('folder_id', $folder->id)->count());
    }

    public function test_search_counts_downloads_and_duplicate_warnings_do_not_leak(): void
    {
        $owner = $this->account();
        $staff = $this->account();
        $admin = $this->account(['role' => Role::Admin->value]);
        $otherAdmin = $this->account(['role' => Role::Admin->value, 'source' => Source::PMS->value]);
        $super = $this->account(['role' => Role::SuperAdmin->value, 'source' => null]);

        $content = 'Phase 4 duplicate bytes for leak testing';
        [$internal, $internalVersion] = $this->upload($owner, 'Internal', 'Phase 4 Internal', content: $content);
        [$confidential, $confidentialVersion] = $this->upload($owner, 'Confidential', 'Phase 4 Confidential', content: $content);
        [$restricted, $restrictedVersion] = $this->upload($owner, 'Restricted', 'Phase 4 Restricted', content: $content);
        $checksum = DB::table('document_versions')->where('id', $internalVersion)->value('checksum');

        $this->assertWorkspaceCount($staff, 'EMS', 1);
        $this->assertWorkspaceCount($admin, 'EMS', 2);
        $this->assertWorkspaceCount($otherAdmin, 'PMS', 0);
        $this->assertWorkspaceCount($super, 'EMS', 3);
        $this->assertAdminCount($admin, 2);
        $this->assertAdminCount($otherAdmin, 0);
        $this->assertAdminCount($super, 3);

        $this->assertWorkspaceSearch($staff, 'EMS', 'Phase 4 Internal', $internal, true);
        $this->assertWorkspaceSearch($staff, 'EMS', 'Phase 4 Confidential', $confidential, false);
        $this->assertWorkspaceSearch($staff, 'EMS', 'Phase 4 Restricted', $restricted, false);
        $this->assertWorkspaceSearch($admin, 'EMS', 'Phase 4 Confidential', $confidential, true);
        $this->assertWorkspaceSearch($admin, 'EMS', 'Phase 4 Restricted', $restricted, false);
        $this->assertWorkspaceSearch($super, 'EMS', 'Phase 4 Restricted', $restricted, true);
        $this->actingAs($otherAdmin)->get('/admin/repository?q=Phase+4+Internal')
            ->assertOk()
            ->assertViewHas('documents', fn ($documents) => $documents->total() === 0);

        foreach ([$internalVersion, $confidentialVersion, $restrictedVersion] as $version) {
            $this->actingAs($otherAdmin)->get('/files/'.$version)->assertNotFound();
        }
        $this->actingAs($staff)->get('/files/'.$internalVersion)->assertOk();
        $this->actingAs($staff)->get('/files/'.$confidentialVersion)->assertNotFound();
        $this->actingAs($staff)->get('/files/'.$restrictedVersion)->assertNotFound();
        $this->actingAs($admin)->get('/files/'.$confidentialVersion)->assertOk();
        $this->actingAs($admin)->get('/files/'.$restrictedVersion)->assertNotFound();
        $this->actingAs($super)->get('/files/'.$restrictedVersion)->assertOk();

        $this->assertCount(1, DuplicateDetection::matches($staff, $checksum));
        $this->assertCount(2, DuplicateDetection::matches($admin, $checksum));
        $this->assertCount(3, DuplicateDetection::matches($super, $checksum));
        $this->assertCount(0, DuplicateDetection::matches($otherAdmin, $checksum));

        $this->actingAs($staff)->get('/documents/'.Str::uuid())->assertNotFound();
        $this->actingAs($staff)->get('/files/'.Str::uuid())->assertNotFound();
    }

    public function test_api_connector_readback_respects_classification(): void
    {
        DB::table('connectors')->insert([
            'name' => 'Phase 4 connector',
            'source' => Source::EMS->value,
            'school_id' => 'PLP',
            'campus' => 'Main',
            'token_hash' => hash('sha256', $this->token),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $internal = $this->apiUpload('API Internal', 'Internal', 'API internal bytes');
        $confidential = $this->apiUpload('API Confidential', 'Confidential', 'API confidential bytes');
        $restricted = $this->apiUpload('API Restricted', 'Restricted', 'API restricted bytes');

        $this->withToken($this->token)->getJson('/api/v1/documents/'.$internal['document_id'])->assertOk();
        $this->withToken($this->token)->getJson('/api/v1/documents/'.$confidential['document_id'])->assertOk();
        $this->withToken($this->token)->getJson('/api/v1/documents/'.$restricted['document_id'])->assertNotFound();
        $this->withToken($this->token)->get('/api/v1/files/'.$restricted['version_id'])->assertNotFound();

        $this->withToken($this->token)->getJson('/api/v1/documents')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['title' => 'API Restricted']);
        $this->withToken($this->token)->getJson('/api/v1/files')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['document_id' => $restricted['document_id']]);
        // Each visible upload has a version-created and a scan-completed event;
        // the Restricted document contributes neither.
        $this->withToken($this->token)->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonMissing(['document_id' => $restricted['document_id']]);
    }

    /**
     * @return array<string, mixed>
     */
    private function approvePending(): void
    {
        foreach (DB::table('sensitive_changes')->where('status', 'pending')->get() as $change) {
            $requester = User::findOrFail($change->requester_id);
            $reviewer = $this->account(['role' => $requester->isAdmin() ? Role::SuperAdmin->value : Role::Admin->value, 'source' => $requester->source]);
            app(SensitiveChangeService::class)->decide($reviewer, $change->id, true, 'Approved fixture change for visibility verification.');
        }
    }

    private function apiUpload(string $title, string $classification, string $content): array
    {
        $result = $this->withToken($this->token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/documents', [
                'title' => $title,
                'classification' => $classification,
                'file' => $this->file(Str::slug($title).'.txt', $content),
            ])->assertCreated()->json();
        (new ScanDocumentVersion($result['version_id']))->handle(app(Scanner::class));

        return $result;
    }

    public function test_existing_documents_default_to_internal(): void
    {
        $id = (string) Str::uuid();
        DB::table('documents')->insert([
            'id' => $id,
            'source' => Source::EMS->value,
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'Default classification document',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('documents', ['id' => $id, 'classification' => 'Internal']);

        // Explicit historical values are preserved, not rewritten.
        $explicit = $this->insertDocument($this->account(), 'Confidential', 'Explicit historical classification');
        $this->assertDatabaseHas('documents', ['id' => $explicit, 'classification' => 'Confidential']);
    }
}
