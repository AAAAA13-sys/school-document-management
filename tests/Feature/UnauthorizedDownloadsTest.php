<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class UnauthorizedDownloadsTest extends TestCase
{
    use RefreshDatabase;

    private string $emsToken = 'unauth-download-ems-token';
    private string $pmsToken = 'unauth-download-pms-token';
    private string $oasToken = 'unauth-download-oas-token';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->mock(Scanner::class, fn ($m) => $m->shouldReceive('scan')->andReturn('Clean'));

        DB::table('connectors')->insert([
            ['name' => 'EMS Connector', 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main', 'token_hash' => hash('sha256', $this->emsToken), 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'PMS Connector', 'source' => 'PMS', 'school_id' => 'PLP', 'campus' => 'Main', 'token_hash' => hash('sha256', $this->pmsToken), 'active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'OAS Connector', 'source' => 'OAS', 'school_id' => 'PLP', 'campus' => 'Main', 'token_hash' => hash('sha256', $this->oasToken), 'active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'role' => 'staff',
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ]);
    }

    private function createDocumentWithVersion(
        User $owner,
        string $classification = 'Internal',
        string $source = 'EMS',
        string $scanStatus = 'Clean',
        string $schoolId = 'PLP',
        string $campus = 'Main',
        ?string $category = null,
        string $content = 'Valid document content'
    ): array {
        $docId = (string) Str::uuid();
        DB::table('documents')->insert([
            'id' => $docId,
            'owner_id' => $owner->id,
            'folder_id' => null,
            'source' => $source,
            'school_id' => $schoolId,
            'campus' => $campus,
            'title' => "Document {$docId}",
            'classification' => $classification,
            'category' => $category,
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = (string) Str::uuid();
        $storageKey = "documents/{$versionId}";
        Storage::disk('local')->put($storageKey, $content);

        DB::table('document_versions')->insert([
            'id' => $versionId,
            'document_id' => $docId,
            'number' => 1,
            'filename' => "sample_{$versionId}.txt",
            'mime' => 'text/plain',
            'size' => strlen($content),
            'checksum' => hash('sha256', $content),
            'storage_key' => $storageKey,
            'scan_status' => $scanStatus,
            'created_at' => now(),
        ]);

        return [$docId, $versionId];
    }

    public function test_download_blocked_when_scan_status_is_pending_infected_or_error(): void
    {
        $owner = $this->user();

        // 1. Pending scan status
        [$docPending, $vPending] = $this->createDocumentWithVersion($owner, scanStatus: 'Pending', content: 'Pending file bytes');
        $this->actingAs($owner)->get("/files/{$vPending}")->assertStatus(423);
        $this->actingAs($owner)->get("/files/{$vPending}/preview")->assertStatus(423);
        $this->withToken($this->emsToken)->get("/api/v1/files/{$vPending}")->assertStatus(423);

        // 2. Infected scan status
        [$docInfected, $vInfected] = $this->createDocumentWithVersion($owner, scanStatus: 'Infected', content: 'Malware bytes');
        $this->actingAs($owner)->get("/files/{$vInfected}")->assertStatus(423);
        $this->actingAs($owner)->get("/files/{$vInfected}/preview")->assertStatus(423);
        $this->withToken($this->emsToken)->get("/api/v1/files/{$vInfected}")->assertStatus(423);

        // 3. Error scan status
        [$docError, $vError] = $this->createDocumentWithVersion($owner, scanStatus: 'Error', content: 'Error bytes');
        $this->actingAs($owner)->get("/files/{$vError}")->assertStatus(423);
        $this->actingAs($owner)->get("/files/{$vError}/preview")->assertStatus(423);
        $this->withToken($this->emsToken)->get("/api/v1/files/{$vError}")->assertStatus(423);

        // 4. Clean scan status succeeds
        [$docClean, $vClean] = $this->createDocumentWithVersion($owner, scanStatus: 'Clean', content: 'Safe bytes');
        $this->actingAs($owner)->get("/files/{$vClean}")->assertOk();
        $this->withToken($this->emsToken)->get("/api/v1/files/{$vClean}")->assertOk();
    }

    public function test_download_isolated_across_schools_and_campuses(): void
    {
        $owner = $this->user(['school_id' => 'PLP', 'campus' => 'Main']);
        [$docId, $vId] = $this->createDocumentWithVersion($owner, schoolId: 'PLP', campus: 'Main');

        // Different school user -> 404
        $otherSchoolUser = $this->user(['school_id' => 'OTHER_UNIV', 'campus' => 'Main']);
        $this->actingAs($otherSchoolUser)->get("/files/{$vId}")->assertNotFound();

        // Different campus user -> 404
        $otherCampusUser = $this->user(['school_id' => 'PLP', 'campus' => 'Extension']);
        $this->actingAs($otherCampusUser)->get("/files/{$vId}")->assertNotFound();

        // Super Admin from different school -> 404
        $foreignSuperAdmin = $this->user(['role' => 'super_admin', 'source' => null, 'school_id' => 'OTHER_UNIV', 'campus' => 'Main']);
        $this->actingAs($foreignSuperAdmin)->get("/files/{$vId}")->assertNotFound();

        // Connector from different school (with valid registered ERP workspace for its school) -> 404
        DB::table('workspaces')->insert([
            'school_id' => 'OTHER_UNIV',
            'campus' => 'Main',
            'code' => 'EMS',
            'name' => 'Other EMS',
            'kind' => 'erp',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignToken = 'foreign-school-token';
        DB::table('connectors')->insert([
            'name' => 'Foreign Connector',
            'source' => 'EMS',
            'school_id' => 'OTHER_UNIV',
            'campus' => 'Main',
            'token_hash' => hash('sha256', $foreignToken),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->withToken($foreignToken)->get("/api/v1/files/{$vId}")->assertNotFound();
    }

    public function test_download_isolated_across_sources_without_explicit_grant(): void
    {
        $ownerEms = $this->user(['role' => 'staff', 'source' => 'EMS']);
        [$docId, $vId] = $this->createDocumentWithVersion($ownerEms, classification: 'Internal', source: 'EMS');

        // Staff in PMS trying to download EMS document without grant -> 404
        $staffPms = $this->user(['role' => 'staff', 'source' => 'PMS']);
        $this->actingAs($staffPms)->get("/files/{$vId}")->assertNotFound();

        // Admin in PMS trying to download EMS document without grant -> 404
        $adminPms = $this->user(['role' => 'admin', 'source' => 'PMS']);
        $this->actingAs($adminPms)->get("/files/{$vId}")->assertNotFound();

        // PMS API connector trying to download EMS document -> 404
        $this->withToken($this->pmsToken)->get("/api/v1/files/{$vId}")->assertNotFound();

        // Same source staff in EMS can download
        $staffEms = $this->user(['role' => 'staff', 'source' => 'EMS']);
        $this->actingAs($staffEms)->get("/files/{$vId}")->assertOk();

        // PMS staff with explicit document share grant can download
        DB::table('shares')->insert([
            'document_id' => $docId,
            'recipient_id' => $staffPms->id,
            'permission' => 'Viewer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($staffPms)->get("/files/{$vId}")->assertOk();
    }

    public function test_download_enforces_classification_visibility_matrix(): void
    {
        $owner = $this->user(['role' => 'staff', 'source' => 'EMS']);
        $otherStaff = $this->user(['role' => 'staff', 'source' => 'EMS']);
        $scopedAdmin = $this->user(['role' => 'admin', 'source' => 'EMS']);
        $superAdmin = $this->user(['role' => 'super_admin', 'source' => null]);

        // 1. Confidential Document
        [$docConf, $vConf] = $this->createDocumentWithVersion($owner, classification: 'Confidential', source: 'EMS');

        // Non-owner staff without grant -> 404
        $this->actingAs($otherStaff)->get("/files/{$vConf}")->assertNotFound();

        // Owner -> 200 OK
        $this->actingAs($owner)->get("/files/{$vConf}")->assertOk();

        // Scoped Admin -> 200 OK
        $this->actingAs($scopedAdmin)->get("/files/{$vConf}")->assertOk();

        // Super Admin -> 200 OK
        $this->actingAs($superAdmin)->get("/files/{$vConf}")->assertOk();

        // Connector in same source can read Confidential
        $this->withToken($this->emsToken)->get("/api/v1/files/{$vConf}")->assertOk();

        // Explicit share grant allows non-owner staff to download Confidential
        DB::table('shares')->insert([
            'document_id' => $docConf,
            'recipient_id' => $otherStaff->id,
            'permission' => 'Viewer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($otherStaff)->get("/files/{$vConf}")->assertOk();

        // 2. Restricted Document
        [$docRest, $vRest] = $this->createDocumentWithVersion($owner, classification: 'Restricted', source: 'EMS');

        // Non-owner staff -> 404
        $this->actingAs($otherStaff)->get("/files/{$vRest}")->assertNotFound();

        // Scoped Admin (non-owner) -> 404 (Restricted is private to owner and Super Admin)
        $this->actingAs($scopedAdmin)->get("/files/{$vRest}")->assertNotFound();

        // Super Admin -> 200 OK
        $this->actingAs($superAdmin)->get("/files/{$vRest}")->assertOk();

        // Connector CANNOT read Restricted documents -> 404
        $this->withToken($this->emsToken)->get("/api/v1/files/{$vRest}")->assertNotFound();

        // 3. Medical / Health category intake restriction for OAS / ES connectors
        $ownerOas = $this->user(['role' => 'staff', 'source' => 'OAS']);
        [$docMed, $vMed] = $this->createDocumentWithVersion($ownerOas, classification: 'Internal', source: 'OAS', category: 'Medical Records');

        // OAS connector attempting to download medical document -> 404
        $this->withToken($this->oasToken)->get("/api/v1/files/{$vMed}")->assertNotFound();
    }

    public function test_download_version_specific_grant_blocks_other_versions(): void
    {
        $owner = $this->user(['role' => 'staff', 'source' => 'EMS']);
        $recipient = $this->user(['role' => 'staff', 'source' => 'EMS']);

        [$docId, $v1] = $this->createDocumentWithVersion($owner, classification: 'Confidential', content: 'Version 1 content');

        // Add version 2
        $v2 = (string) Str::uuid();
        $storageKey2 = "documents/{$v2}";
        Storage::disk('local')->put($storageKey2, 'Version 2 content');
        DB::table('document_versions')->insert([
            'id' => $v2,
            'document_id' => $docId,
            'number' => 2,
            'filename' => 'version2.txt',
            'mime' => 'text/plain',
            'size' => 17,
            'checksum' => hash('sha256', 'Version 2 content'),
            'storage_key' => $storageKey2,
            'scan_status' => 'Clean',
            'created_at' => now(),
        ]);
        DB::table('documents')->where('id', $docId)->update(['revision' => 2]);

        // Grant recipient access SPECIFICALLY to Version 1 only
        DB::table('shares')->insert([
            'document_id' => $docId,
            'recipient_id' => $recipient->id,
            'version_id' => $v1,
            'permission' => 'Viewer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Recipient can download Version 1
        $this->actingAs($recipient)->get("/files/{$v1}")->assertOk();

        // Recipient MUST be forbidden from downloading Version 2
        $this->actingAs($recipient)->get("/files/{$v2}")->assertStatus(403);
        $this->actingAs($recipient)->get("/files/{$v2}/preview")->assertStatus(403);
    }

    public function test_download_uncirculated_memo_version_is_blocked_for_readers_and_connectors(): void
    {
        $owner = $this->user(['role' => 'staff', 'source' => 'EMS']);
        $reader = $this->user(['role' => 'staff', 'source' => 'EMS']);

        [$docId, $v1] = $this->createDocumentWithVersion($owner, classification: 'Internal', content: 'Circulated memo v1');

        // Publish memo pinning Version 1
        DB::table('memo_publications')->insert([
            'document_id' => $docId,
            'version_id' => $v1,
            'type' => 'Office memo',
            'reference' => 'REF-001',
            'issuer' => 'Registrar',
            'audience' => json_encode([$reader->id]),
            'published_at' => now(),
        ]);

        // Add version 2 (unreleased draft)
        $v2 = (string) Str::uuid();
        $storageKey2 = "documents/{$v2}";
        Storage::disk('local')->put($storageKey2, 'Uncirculated draft v2');
        DB::table('document_versions')->insert([
            'id' => $v2,
            'document_id' => $docId,
            'number' => 2,
            'filename' => 'memo_v2.txt',
            'mime' => 'text/plain',
            'size' => 21,
            'checksum' => hash('sha256', 'Uncirculated draft v2'),
            'storage_key' => $storageKey2,
            'scan_status' => 'Clean',
            'created_at' => now(),
        ]);

        // Reader can download circulated Version 1
        $this->actingAs($reader)->get("/files/{$v1}")->assertOk();

        // Reader is forbidden from downloading uncirculated Version 2
        $respReaderV2 = $this->actingAs($reader)->get("/files/{$v2}");
        $respReaderV2->assertStatus(403);

        // Connector can download circulated Version 1
        $this->withToken($this->emsToken)->get("/api/v1/files/{$v1}")->assertOk();

        // Connector is forbidden from downloading uncirculated Version 2
        $respConnV2 = $this->withToken($this->emsToken)->get("/api/v1/files/{$v2}");
        $respConnV2->assertStatus(403);
    }

    public function test_download_trashed_document_is_rejected(): void
    {
        $owner = $this->user();
        [$docId, $vId] = $this->createDocumentWithVersion($owner);

        // Mark document as trashed
        DB::table('documents')->where('id', $docId)->update(['trashed_at' => now()]);

        // Web download returns 404
        $this->actingAs($owner)->get("/files/{$vId}")->assertNotFound();

        // Web preview returns 404
        $this->actingAs($owner)->get("/files/{$vId}/preview")->assertNotFound();

        // API download returns 404
        $this->withToken($this->emsToken)->get("/api/v1/files/{$vId}")->assertNotFound();
    }

    public function test_download_blocks_tampered_bytes_and_missing_disk_files_with_409(): void
    {
        $owner = $this->user();

        // 1. Missing file on disk
        [$docMissing, $vMissing] = $this->createDocumentWithVersion($owner, content: 'Bytes to be deleted');
        Storage::disk('local')->delete("documents/{$vMissing}");

        $respMissing = $this->actingAs($owner)->get("/files/{$vMissing}");
        $respMissing->assertStatus(409);

        // 2. Tampered file bytes (checksum mismatch)
        [$docTampered, $vTampered] = $this->createDocumentWithVersion($owner, content: 'Original genuine bytes');
        // Overwrite disk bytes without updating DB checksum
        Storage::disk('local')->put("documents/{$vTampered}", 'Altered malicious bytes');

        $respTampered = $this->actingAs($owner)->get("/files/{$vTampered}");
        $respTampered->assertStatus(409);
    }

    public function test_download_blocked_for_inactive_users_and_connectors(): void
    {
        $owner = $this->user();
        [$docId, $vId] = $this->createDocumentWithVersion($owner);

        // Suspended / inactive user
        $inactiveUser = $this->user(['active' => false]);
        $respInactive = $this->actingAs($inactiveUser)->get("/files/{$vId}");
        $respInactive->assertStatus(403);

        // Revoked / inactive connector
        $revokedToken = 'revoked-connector-token';
        DB::table('connectors')->insert([
            'name' => 'Revoked Connector',
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'token_hash' => hash('sha256', $revokedToken),
            'active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $respRevoked = $this->withToken($revokedToken)->get("/api/v1/files/{$vId}");
        $respRevoked->assertStatus(401);
    }

    public function test_download_and_preview_audit_logging_and_no_leak_on_failure(): void
    {
        $owner = $this->user();
        [$docId, $vId] = $this->createDocumentWithVersion($owner, content: 'Audit test bytes');

        // Successful download generates audit entry
        $this->actingAs($owner)->get("/files/{$vId}")->assertOk();
        $this->assertDatabaseHas('audit_entries', [
            'document_id' => $docId,
            'action' => 'document.downloaded',
            'actor_id' => $owner->id,
        ]);

        // Successful preview generates previewed audit entry
        $this->actingAs($owner)->get("/files/{$vId}/preview")->assertOk();
        $this->assertDatabaseHas('audit_entries', [
            'document_id' => $docId,
            'action' => 'document.previewed',
            'actor_id' => $owner->id,
        ]);

        // Unauthorized download attempt MUST NOT generate a false document.downloaded audit entry
        $unauthStaff = $this->user(['role' => 'staff', 'source' => 'PMS']);
        $countBefore = DB::table('audit_entries')->where('action', 'document.downloaded')->count();

        $this->actingAs($unauthStaff)->get("/files/{$vId}")->assertNotFound();

        $countAfter = DB::table('audit_entries')->where('action', 'document.downloaded')->count();
        $this->assertSame($countBefore, $countAfter);
    }
}
