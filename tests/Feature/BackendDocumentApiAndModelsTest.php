<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Comment;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Folder;
use App\Models\Share;
use App\Models\Star;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackendDocumentApiAndModelsTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $emsAdmin;

    private User $emsStaff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@plp.edu.ph',
            'password' => 'Pass!234word',
            'role' => Role::SuperAdmin->value,
            'source' => null,
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ]);

        $this->emsAdmin = User::create([
            'name' => 'EMS Admin',
            'email' => 'admin@ems.plp.edu.ph',
            'password' => 'Pass!234word',
            'role' => Role::Admin->value,
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ]);

        $this->emsStaff = User::create([
            'name' => 'EMS Staff',
            'email' => 'staff@ems.plp.edu.ph',
            'password' => 'Pass!234word',
            'role' => Role::Staff->value,
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ]);

        Workspace::firstOrCreate(
            ['school_id' => 'PLP', 'campus' => 'Main', 'code' => 'EMS'],
            ['name' => 'Employee Management System', 'kind' => 'erp', 'status' => 'active']
        );
    }

    public function test_document_and_user_eloquent_models_and_relationships(): void
    {
        $folder = Folder::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $this->emsStaff->id,
            'name' => 'Staff Folder',
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'revision' => 1,
        ]);

        $doc = Document::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $this->emsStaff->id,
            'folder_id' => $folder->id,
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'Test Document',
            'classification' => 'Internal',
            'revision' => 1,
        ]);

        $version = DocumentVersion::create([
            'id' => (string) Str::uuid(),
            'document_id' => $doc->id,
            'number' => 1,
            'filename' => 'test.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'checksum' => hash('sha256', 'dummy-content'),
            'storage_key' => 'documents/'.(string) Str::uuid(),
            'scan_status' => 'Clean',
            'created_at' => now(),
        ]);

        Comment::create([
            'document_id' => $doc->id,
            'user_id' => $this->emsStaff->id,
            'body' => 'Initial comment on document.',
            'created_at' => now(),
        ]);

        Star::create([
            'document_id' => $doc->id,
            'user_id' => $this->emsStaff->id,
        ]);

        Share::create([
            'document_id' => $doc->id,
            'recipient_id' => $this->emsAdmin->id,
            'permission' => 'Viewer',
        ]);

        // Model relationship checks
        $this->assertEquals($this->emsStaff->id, $doc->owner->id);
        $this->assertEquals($folder->id, $doc->folder->id);
        $this->assertCount(1, $doc->versions);
        $this->assertEquals($version->id, $doc->latestVersion->id);
        $this->assertTrue($doc->latestVersion->isScannedClean());
        $this->assertCount(1, $doc->comments);
        $this->assertCount(1, $doc->stars);
        $this->assertCount(1, $doc->shares);

        // User relationship checks
        $this->assertCount(1, $this->emsStaff->documents);
        $this->assertCount(1, $this->emsStaff->folders);
        $this->assertCount(1, $this->emsStaff->comments);
        $this->assertCount(1, $this->emsStaff->stars);
        $this->assertCount(1, $this->emsAdmin->shares);

        // Model helpers and scopes
        $this->assertTrue($this->emsStaff->isStaff());
        $this->assertTrue($this->emsAdmin->isAdmin());
        $this->assertTrue($this->superAdmin->isSuperAdmin());
        $this->assertTrue($this->emsStaff->hasRole('staff'));
        $this->assertTrue($doc->isInternal());
        $this->assertFalse($doc->isTrashed());

        $this->assertEquals(1, Document::untrashed()->count());
        $this->assertEquals(0, Document::trashed()->count());
        $this->assertEquals(1, Document::inSource('EMS')->count());
    }

    public function test_get_documents_endpoint_returns_json_list(): void
    {
        $doc = Document::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $this->emsStaff->id,
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'Policy Manual',
            'classification' => 'Internal',
            'revision' => 1,
        ]);

        DocumentVersion::create([
            'id' => (string) Str::uuid(),
            'document_id' => $doc->id,
            'number' => 1,
            'filename' => 'policy.pdf',
            'mime' => 'application/pdf',
            'size' => 2048,
            'checksum' => hash('sha256', 'policy-manual'),
            'storage_key' => 'documents/'.(string) Str::uuid(),
            'scan_status' => 'Clean',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->emsStaff)
            ->getJson('/documents?source=EMS');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'source',
                        'classification',
                        'revision',
                        'latest_version' => [
                            'id',
                            'number',
                            'filename',
                            'mime',
                            'size',
                            'scan_status',
                        ],
                        'permissions' => [
                            'can_edit',
                            'can_manage',
                            'can_share',
                        ],
                    ],
                ],
                'meta' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                ],
            ]);

        $this->assertEquals(1, $response->json('meta.total'));
        $this->assertEquals('Policy Manual', $response->json('data.0.title'));
    }

    public function test_get_document_details_json(): void
    {
        $doc = Document::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $this->emsStaff->id,
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'Employment Contract',
            'classification' => 'Confidential',
            'revision' => 1,
        ]);

        $version = DocumentVersion::create([
            'id' => (string) Str::uuid(),
            'document_id' => $doc->id,
            'number' => 1,
            'filename' => 'contract.pdf',
            'mime' => 'application/pdf',
            'size' => 4096,
            'checksum' => hash('sha256', 'contract-sample'),
            'storage_key' => 'documents/'.(string) Str::uuid(),
            'scan_status' => 'Clean',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->emsStaff)
            ->getJson('/documents/'.$doc->id);

        $response->assertOk()
            ->assertJsonPath('document.id', $doc->id)
            ->assertJsonPath('document.title', 'Employment Contract')
            ->assertJsonPath('versions.0.id', $version->id)
            ->assertJsonPath('permissions.can_edit', true);
    }

    public function test_toggle_star_returns_json(): void
    {
        $doc = Document::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $this->emsStaff->id,
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'Important Memo',
            'classification' => 'Internal',
            'revision' => 1,
        ]);

        $response = $this->actingAs($this->emsStaff)
            ->postJson('/documents/'.$doc->id.'/star');

        $response->assertOk()
            ->assertJson(['starred' => true, 'document_id' => $doc->id]);

        $this->assertDatabaseHas('stars', [
            'document_id' => $doc->id,
            'user_id' => $this->emsStaff->id,
        ]);

        // Toggle off
        $response2 = $this->actingAs($this->emsStaff)
            ->postJson('/documents/'.$doc->id.'/star');

        $response2->assertOk()
            ->assertJson(['starred' => false, 'document_id' => $doc->id]);

        $this->assertDatabaseMissing('stars', [
            'document_id' => $doc->id,
            'user_id' => $this->emsStaff->id,
        ]);
    }

    public function test_add_comment_returns_json(): void
    {
        $doc = Document::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $this->emsStaff->id,
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'Public Notice',
            'classification' => 'Internal',
            'revision' => 1,
        ]);

        $response = $this->actingAs($this->emsStaff)
            ->postJson('/documents/'.$doc->id.'/comments', [
                'body' => 'Looks great for publication.',
            ]);

        $response->assertStatus(201)
            ->assertJson(['message' => 'Comment added.']);

        $this->assertDatabaseHas('comments', [
            'document_id' => $doc->id,
            'user_id' => $this->emsStaff->id,
            'body' => 'Looks great for publication.',
        ]);
    }

    public function test_get_account_profile_returns_json(): void
    {
        $response = $this->actingAs($this->emsStaff)
            ->getJson('/account');

        $response->assertOk()
            ->assertJsonPath('user.id', $this->emsStaff->id)
            ->assertJsonPath('user.email', 'staff@ems.plp.edu.ph')
            ->assertJsonPath('user.role', 'staff')
            ->assertJsonPath('user.source', 'EMS')
            ->assertJsonPath('user.school_id', 'PLP')
            ->assertJsonPath('user.campus', 'Main');
    }

    public function test_workspace_index_returns_json(): void
    {
        Document::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $this->emsStaff->id,
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'Workspace Item',
            'classification' => 'Internal',
            'revision' => 1,
        ]);

        $response = $this->actingAs($this->emsStaff)
            ->getJson('/workspaces/ems/documents');

        $response->assertOk()
            ->assertJsonStructure([
                'source',
                'section',
                'stats',
                'view',
                'documents',
            ]);
    }
}
