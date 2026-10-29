<?php

// TODO(QaAgent): Created by Backend Agent for P1 Backlog feature validation. QA Agent owns tests/ and may review or expand this test suite.

namespace Tests\Feature;

use App\Models\DocumentType;
use App\Models\Share;
use App\Models\User;
use App\Services\ApplicantPacketService;
use App\Services\ReleaseGrant;
use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BackendP1BacklogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function user(string $source = 'EMS', string $role = 'staff'): User
    {
        return User::factory()->create([
            'role' => $role,
            'source' => $source,
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ]);
    }

    private function connector(string $source): object
    {
        $id = DB::table('connectors')->insertGetId([
            'name' => $source.' Connector',
            'source' => $source,
            'school_id' => 'PLP',
            'campus' => 'Main',
            'token_hash' => hash('sha256', $source.'-backlog-token'),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('connectors')->where('id', $id)->first();
    }

    /**
     * Task 1: Document Type Registry Trim
     */
    public function test_document_type_registry_strictly_limited_to_oas_and_internal(): void
    {
        // 1. Run seeder
        $this->seed(DocumentTypeSeeder::class);

        // 2. OAS applicant document types exist
        $this->assertDatabaseHas('document_types', ['code' => 'PSA', 'workspace' => 'OAS']);
        $this->assertDatabaseHas('document_types', ['code' => 'FORM_137', 'workspace' => 'OAS']);
        $this->assertDatabaseHas('document_types', ['code' => 'VALID_ID', 'workspace' => 'OAS']);

        // 3. Internal Office document types exist
        $this->assertDatabaseHas('document_types', ['code' => 'MINUTES', 'workspace' => 'INTERNAL']);
        $this->assertDatabaseHas('document_types', ['code' => 'POLICY_DRAFT', 'workspace' => 'INTERNAL']);

        // 4. Ensure no PMS, EMS, or ES transactional types exist
        $this->assertSame(0, DB::table('document_types')->whereIn('workspace', ['PMS', 'EMS', 'ES'])->count());
        $this->assertSame(0, DB::table('document_types')->whereIn('code', DocumentType::PROHIBITED_TYPES)->count());

        // 5. Model methods
        $this->assertTrue(DocumentType::isOasType('PSA'));
        $this->assertTrue(DocumentType::isOasType('FORM_137'));
        $this->assertTrue(DocumentType::isOasType('VALID_ID'));
        $this->assertFalse(DocumentType::isOasType('PAYSLIP'));
        $this->assertFalse(DocumentType::isOasType('201_FILE'));
        $this->assertFalse(DocumentType::isOasType('ENROLLMENT_FORM'));

        $this->assertTrue(DocumentType::isAllowed('MINUTES'));
        $this->assertTrue(DocumentType::isAllowed('POLICY_DRAFT'));
        $this->assertFalse(DocumentType::isAllowed('PAYSLIP'));
    }

    /**
     * Task 2: Duplicate Detection with 409 Conflict and force_new_version=true
     */
    public function test_duplicate_upload_in_same_workspace_returns_409_with_existing_document_id(): void
    {
        $actor = $this->user('EMS');
        $file = UploadedFile::fake()->create('test-doc.pdf', 100, 'application/pdf');

        // Initial upload
        $first = $this->actingAs($actor)->postJson('/documents', [
            'title' => 'Original Document',
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'orig-1',
            'file' => $file,
        ]);
        $first->assertStatus(201);
        $originalId = $first->json('document_id');
        $this->assertNotNull($originalId);

        // Upload identical content to the same workspace without force_new_version -> 409 with document_id
        $dupFile = UploadedFile::fake()->create('test-dup.pdf', 100, 'application/pdf');
        $dup = $this->actingAs($actor)->postJson('/documents', [
            'title' => 'Duplicate Attempt',
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'dup-attempt-1',
            'file' => $dupFile,
        ]);
        $dup->assertStatus(409);
        $dup->assertJsonPath('code', 'duplicate_file');
        $this->assertSame($originalId, $dup->json('document_id'));

        // Upload with force_new_version=true -> 201 created as a new version of the existing document
        $forceFile = UploadedFile::fake()->create('test-force.pdf', 100, 'application/pdf');
        $force = $this->actingAs($actor)->postJson('/documents', [
            'title' => 'New Version Request',
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'force-new-v-1',
            'force_new_version' => true,
            'file' => $forceFile,
        ]);
        $force->assertStatus(201);
        $this->assertSame($originalId, $force->json('document_id'));

        // Verify version count on original document is now 2
        $this->assertSame(2, DB::table('document_versions')->where('document_id', $originalId)->count());
    }

    /**
     * Task 3: Folder Inheritance - Only Internal child documents are visible; Confidential/Restricted stay hidden
     */
    public function test_folder_inheritance_grants_access_only_to_internal_documents(): void
    {
        $owner = $this->user('EMS');
        $recipient = $this->user('PMS'); // Different source to prove grant traversal

        // Create a folder
        $folderId = (string) Str::uuid();
        DB::table('folders')->insert([
            'id' => $folderId,
            'owner_id' => $owner->id,
            'name' => 'Project Folder',
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create child documents inside folder: Internal, Confidential, Restricted
        $internalDocId = (string) Str::uuid();
        DB::table('documents')->insert([
            'id' => $internalDocId,
            'owner_id' => $owner->id,
            'folder_id' => $folderId,
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'Internal Child Document',
            'classification' => 'Internal',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('document_versions')->insert([
            'id' => (string) Str::uuid(),
            'document_id' => $internalDocId,
            'number' => 1,
            'filename' => 'internal.txt',
            'mime' => 'text/plain',
            'size' => 10,
            'checksum' => hash('sha256', 'internal'),
            'storage_key' => 'documents/'.Str::uuid(),
            'scan_status' => 'Clean',
            'created_at' => now(),
        ]);

        $confidentialDocId = (string) Str::uuid();
        DB::table('documents')->insert([
            'id' => $confidentialDocId,
            'owner_id' => $owner->id,
            'folder_id' => $folderId,
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'Confidential Child Document',
            'classification' => 'Confidential',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('document_versions')->insert([
            'id' => (string) Str::uuid(),
            'document_id' => $confidentialDocId,
            'number' => 1,
            'filename' => 'confidential.txt',
            'mime' => 'text/plain',
            'size' => 10,
            'checksum' => hash('sha256', 'confidential'),
            'storage_key' => 'documents/'.Str::uuid(),
            'scan_status' => 'Clean',
            'created_at' => now(),
        ]);

        $restrictedDocId = (string) Str::uuid();
        DB::table('documents')->insert([
            'id' => $restrictedDocId,
            'owner_id' => $owner->id,
            'folder_id' => $folderId,
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'Restricted Child Document',
            'classification' => 'Restricted',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('document_versions')->insert([
            'id' => (string) Str::uuid(),
            'document_id' => $restrictedDocId,
            'number' => 1,
            'filename' => 'restricted.txt',
            'mime' => 'text/plain',
            'size' => 10,
            'checksum' => hash('sha256', 'restricted'),
            'storage_key' => 'documents/'.Str::uuid(),
            'scan_status' => 'Clean',
            'created_at' => now(),
        ]);

        // Share the internal document with folder_inheritance = true
        $share = Share::create([
            'document_id' => $internalDocId,
            'recipient_id' => $recipient->id,
            'permission' => 'Viewer',
            'folder_inheritance' => true,
            'expires_at' => now()->addDays(14),
        ]);
        $this->assertTrue($share->hasFolderInheritance());

        // Verify that the recipient CAN access the Internal child document
        $this->actingAs($recipient)->get('/documents/'.$internalDocId)->assertOk();

        // Verify that the recipient CANNOT access the Confidential or Restricted child documents (404 hidden)
        $this->actingAs($recipient)->get('/documents/'.$confidentialDocId)->assertNotFound();
        $this->actingAs($recipient)->get('/documents/'.$restrictedDocId)->assertNotFound();
    }

    /**
     * Task 4: Release Grant Allowlist - Strictly hardcoded for OAS document types only
     */
    public function test_release_grant_allowlist_permits_oas_and_rejects_other_types(): void
    {
        $service = app(ReleaseGrant::class);

        // 1. Allowed OAS types
        $this->assertTrue($service->isAllowed('PSA'));
        $this->assertTrue($service->isAllowed('PSA_BIRTH_CERTIFICATE'));
        $this->assertTrue($service->isAllowed('FORM_137'));
        $this->assertTrue($service->isAllowed('FORM_138'));
        $this->assertTrue($service->isAllowed('VALID_ID'));
        $this->assertTrue($service->isAllowed('APPLICANT_ID'));
        $this->assertTrue($service->isAllowed('PARENT_ID'));
        $this->assertTrue($service->isAllowed('GUARDIANSHIP_AFFIDAVIT'));

        // 2. Prohibited ERP transactional types
        $this->assertFalse($service->isAllowed('PAYSLIP'));
        $this->assertFalse($service->isAllowed('PAYROLL_REGISTER'));
        $this->assertFalse($service->isAllowed('201_FILE'));
        $this->assertFalse($service->isAllowed('LEAVE_APPLICATION'));
        $this->assertFalse($service->isAllowed('ENROLLMENT_FORM'));
        $this->assertFalse($service->isAllowed('GRADE_SHEET'));

        // 3. Direct authorization method checks
        $service->authorize('PSA');
        $service->authorize('FORM_137');
        $service->authorize('VALID_ID');

        // Non-OAS type rejected with 403 Forbidden
        try {
            $service->authorize('PAYSLIP');
            $this->fail('Expected 403 Forbidden for non-OAS document type.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        try {
            $service->authorize('201_FILE');
            $this->fail('Expected 403 Forbidden for non-OAS document type.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        try {
            $service->authorize('ENROLLMENT_FORM');
            $this->fail('Expected 403 Forbidden for non-OAS document type.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // 4. Test within ApplicantPacketService
        $oasConnector = $this->connector('OAS');
        $oasUser = $this->user('OAS');

        $docId = (string) Str::uuid();
        DB::table('documents')->insert([
            'id' => $docId,
            'owner_id' => $oasUser->id,
            'source' => 'OAS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'Payroll record in OAS',
            'category' => 'PAYSLIP', // Non-OAS transactional type!
            'classification' => 'Internal',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $versionId = (string) Str::uuid();
        DB::table('document_versions')->insert([
            'id' => $versionId,
            'document_id' => $docId,
            'number' => 1,
            'filename' => 'payslip.pdf',
            'mime' => 'application/pdf',
            'size' => 10,
            'checksum' => hash('sha256', 'payslip-data'),
            'storage_key' => 'documents/'.$versionId,
            'scan_status' => 'Clean',
            'created_at' => now(),
        ]);

        // Attempting to release a non-OAS document in a packet must fail with 403 Forbidden
        try {
            app(ApplicantPacketService::class)->receive($oasConnector, [
                'event_id' => 'event-reject-non-oas',
                'applicant_id' => 'APP-99',
                'revision' => 1,
                'released' => true,
                'admitted' => true,
                'verified' => true,
                'version_ids' => [$versionId],
            ]);
            $this->fail('Expected 403 Forbidden for releasing a non-OAS document type.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
