<?php

namespace Tests\Feature;

use App\Models\AccountChange;
use App\Models\AccountInvitation;
use App\Models\ApplicantPacket;
use App\Models\AuditLegalHold;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\MemoPublication;
use App\Models\PacketReceipt;
use App\Models\SensitiveChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class SensitiveChangeAndApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    private User $admin;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@plp.edu.ph',
            'password' => 'secret123',
            'role' => 'super_admin',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'EMS Admin',
            'email' => 'admin@plp.edu.ph',
            'password' => 'secret123',
            'role' => 'admin',
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ]);

        $this->staff = User::create([
            'name' => 'Faculty Staff',
            'email' => 'staff@plp.edu.ph',
            'password' => 'secret123',
            'role' => 'staff',
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ]);
    }

    public function test_eloquent_models_and_relations_work_properly(): void
    {
        $doc = Document::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $this->staff->id,
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'Test Model Document',
            'classification' => 'Internal',
            'revision' => 1,
        ]);

        $change = SensitiveChange::create([
            'id' => (string) Str::uuid(),
            'requester_id' => $this->staff->id,
            'document_id' => $doc->id,
            'action' => 'document.trash',
            'requester_role' => 'staff',
            'revision' => 1,
            'payload' => ['reason' => 'Test Reason'],
            'status' => 'pending',
        ]);

        $this->assertTrue($change->isPending());
        $this->assertEquals($this->staff->id, $change->requester->id);
        $this->assertEquals($doc->id, $change->document->id);
        $this->assertTrue($this->staff->sensitiveChangesRequested()->exists());
        $this->assertTrue($doc->sensitiveChanges()->exists());

        // Test MemoPublication model
        $version = DocumentVersion::create([
            'id' => (string) Str::uuid(),
            'document_id' => $doc->id,
            'number' => 1,
            'filename' => 'memo.pdf',
            'mime' => 'application/pdf',
            'size' => 1024,
            'checksum' => hash('sha256', 'dummy'),
            'storage_key' => 'docs/memo.pdf',
            'scan_status' => 'Clean',
        ]);

        $memo = MemoPublication::create([
            'document_id' => $doc->id,
            'version_id' => $version->id,
            'type' => 'Memo',
            'reference' => 'REF-001',
            'issuer' => 'VPAA',
            'audience' => [$this->staff->id],
            'published_at' => now(),
        ]);

        $this->assertEquals($doc->id, $memo->document->id);
        $this->assertEquals($version->id, $memo->version->id);
        $this->assertNotNull($doc->memoPublication);

        // Test AccountInvitation model
        $invitation = AccountInvitation::create([
            'id' => (string) Str::uuid(),
            'requested_by' => $this->admin->id,
            'name' => 'New Staff',
            'email' => 'newstaff@plp.edu.ph',
            'role' => 'staff',
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'status' => 'pending',
        ]);

        $this->assertTrue($invitation->isPending());
        $this->assertEquals($this->admin->id, $invitation->requester->id);
        $this->assertTrue($this->admin->accountInvitationsRequested()->exists());

        // Test AccountChange model
        $acctChange = AccountChange::create([
            'id' => (string) Str::uuid(),
            'requester_id' => $this->admin->id,
            'account_id' => $this->staff->id,
            'snapshot' => hash('sha256', 'snap'),
            'payload' => json_encode(['role' => 'admin']),
            'status' => 'pending',
        ]);

        $this->assertTrue($acctChange->isPending());
        $this->assertEquals($this->staff->id, $acctChange->account->id);

        // Test ApplicantPacket and PacketReceipt models
        $packet = ApplicantPacket::create([
            'id' => (string) Str::uuid(),
            'school_id' => 'PLP',
            'campus' => 'Main',
            'applicant_id' => 'APP-101',
            'revision' => 1,
            'released' => true,
            'versions' => [$version->id],
        ]);

        $this->assertTrue($packet->isReleased());

        $receipt = PacketReceipt::create([
            'scope_key' => hash('sha256', 'scope'),
            'event_id' => 'EVT-01',
            'applicant_id' => 'APP-101',
            'revision' => 1,
            'request_hash' => hash('sha256', 'req'),
            'payload' => ['admitted' => true],
            'response' => ['packet_id' => $packet->id],
            'created_at' => now(),
        ]);

        $this->assertEquals('APP-101', $receipt->applicant_id);

        // Test AuditLegalHold model
        $hold = AuditLegalHold::create([
            'school_id' => 'PLP',
            'campus' => 'Main',
            'reason' => 'Audit review hold',
            'actor_id' => $this->superAdmin->id,
        ]);

        $this->assertTrue($hold->isActive());
        $this->assertEquals($this->superAdmin->id, $hold->actor->id);
    }

    public function test_approvals_index_returns_json(): void
    {
        $doc = Document::create([
            'id' => (string) Str::uuid(),
            'owner_id' => $this->staff->id,
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'Pending Proposal Doc',
            'classification' => 'Internal',
            'revision' => 1,
        ]);

        SensitiveChange::create([
            'id' => (string) Str::uuid(),
            'requester_id' => $this->staff->id,
            'document_id' => $doc->id,
            'action' => 'document.trash',
            'requester_role' => 'staff',
            'revision' => 1,
            'payload' => ['reason' => 'Proposal for deletion'],
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/approvals');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'document_id',
                        'document_title',
                        'action',
                        'status',
                        'requester',
                    ],
                ],
                'total',
            ]);
    }

    public function test_invitations_json_endpoints(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
        Mail::fake();

        $response = $this->actingAs($this->superAdmin)
            ->postJson('/admin/invitations', [
                'name' => 'Alice Admin',
                'email' => 'alice@plp.edu.ph',
                'role' => 'admin',
                'source' => 'PMS',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'status', 'message']);

        $listResponse = $this->actingAs($this->superAdmin)
            ->getJson('/admin/invitations');

        $listResponse->assertStatus(200);
    }

    public function test_workspace_registry_json_endpoints(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->postJson('/admin/workspaces', [
                'code' => 'RESEARCH',
                'name' => 'Research Dept',
                'kind' => 'internal',
            ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['code' => 'RESEARCH']);

        $listResponse = $this->actingAs($this->superAdmin)
            ->getJson('/admin/workspaces');

        $listResponse->assertStatus(200)
            ->assertJsonStructure(['workspaces', 'users', 'memberships']);
    }

    public function test_legal_holds_web_and_json_endpoints(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->postJson('/admin/legal-holds', [
                'reason' => 'Litigation hold for audit trails',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'legal_hold']);

        $holdId = $response->json('legal_hold.id');

        $listResponse = $this->actingAs($this->superAdmin)
            ->getJson('/admin/legal-holds');

        $listResponse->assertStatus(200);

        $releaseResponse = $this->actingAs($this->superAdmin)
            ->postJson("/admin/legal-holds/{$holdId}/release", [
                'reason' => 'Case dismissed by tribunal',
            ]);

        $releaseResponse->assertStatus(200)
            ->assertJsonFragment(['status' => 'released']);
    }

    public function test_admin_overview_returns_json(): void
    {
        $response = $this->actingAs($this->superAdmin)
            ->getJson('/admin/overview');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'section',
                'stats' => ['documents', 'accounts', 'history', 'connectors', 'blocked'],
                'workspace_counts',
            ]);
    }
}
