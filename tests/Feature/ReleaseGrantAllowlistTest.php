<?php

namespace Tests\Feature;

use App\Services\ApplicantPacketService;
use App\Services\ReleaseGrant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

// Require stub if backend agent has not yet placed it in app/Services
require_once __DIR__.'/../support/ReleaseGrant.php';

/**
 * Task 4: Release Grant Allowlist Tests (QA P1 Backlog)
 *
 * Requirements:
 * - Attempt to create a ReleaseGrant for a PMS document type. Assert 403 Forbidden.
 * - Attempt one for an OAS type. Assert 201 Created.
 *
 * // TODO(BackendAgent): In app/Services/ReleaseGrant.php, enforce strict allowlist
 * // for OAS document types only (PSA, Form 137, Valid ID). Reject PMS/EMS/ES with 403 Forbidden.
 */
class ReleaseGrantAllowlistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * Test creating a ReleaseGrant for a PMS document type asserts 403 Forbidden.
     */
    public function test_creating_release_grant_for_pms_document_type_returns_403_forbidden(): void
    {
        $service = new ReleaseGrant();

        // 1. Attempt with standard PMS payslip type
        try {
            $service->create([
                'document_type' => 'PMS_PAYSLIP',
                'applicant_id' => 'A-2026-001',
            ]);
            $this->fail('Creating ReleaseGrant for PMS_PAYSLIP must abort with 403 Forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode(), 'Must return 403 status code for PMS document type.');
            $this->assertTrue(str_contains(strtolower($e->getMessage()), 'release') || str_contains(strtolower($e->getMessage()), 'not permitted') || str_contains(strtolower($e->getMessage()), 'not authorized'));
        }

        // 2. Attempt with generic PMS payroll type
        try {
            $service->create([
                'document_type' => 'PMS_PAYROLL_SUMMARY',
                'applicant_id' => 'A-2026-002',
            ]);
            $this->fail('Creating ReleaseGrant for PMS_PAYROLL_SUMMARY must abort with 403 Forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // 3. Attempt with EMS employee document type
        try {
            $service->create([
                'document_type' => 'EMS_CONTRACT',
                'applicant_id' => 'A-2026-003',
            ]);
            $this->fail('Creating ReleaseGrant for EMS document type must abort with 403 Forbidden.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /**
     * Test creating a ReleaseGrant for an OAS type asserts 201 Created.
     */
    public function test_creating_release_grant_for_oas_document_type_returns_201_created(): void
    {
        $service = new ReleaseGrant();

        // 1. Attempt with OAS PSA birth certificate
        $resultPsa = $service->create([
            'document_type' => 'PSA_BIRTH_CERTIFICATE',
            'applicant_id' => 'A-2026-101',
        ]);

        $this->assertIsArray($resultPsa);
        $this->assertSame(201, $resultPsa['code'], 'Must return 201 Created code for OAS type.');
        $this->assertSame('created', $resultPsa['status']);
        $this->assertNotEmpty($resultPsa['grant_id']);
        $this->assertSame('PSA_BIRTH_CERTIFICATE', $resultPsa['document_type']);

        // 2. Attempt with OAS Form 137
        $resultForm137 = $service->create([
            'document_type' => 'FORM_137',
            'applicant_id' => 'A-2026-102',
        ]);
        $this->assertSame(201, $resultForm137['code']);
        $this->assertSame('FORM_137', $resultForm137['document_type']);

        // 3. Attempt with OAS Valid ID
        $resultId = $service->create([
            'document_type' => 'VALID_ID',
            'applicant_id' => 'A-2026-103',
        ]);
        $this->assertSame(201, $resultId['code']);
    }

    /**
     * Integration assertion: ERP connector release boundary verifies OAS allowlist vs PMS rejection.
     */
    public function test_api_packet_release_strictly_authorizes_oas_and_rejects_pms(): void
    {
        // Set up OAS and PMS connectors
        foreach (['OAS', 'PMS'] as $source) {
            DB::table('connectors')->insert([
                'name' => $source.' Connector',
                'source' => $source,
                'school_id' => 'PLP',
                'campus' => 'Main',
                'token_hash' => hash('sha256', 'token-'.$source),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // PMS connector attempting to create a packet release must be rejected with 403 Forbidden
        $pmsResponse = $this->withToken('token-PMS')->postJson('/api/v1/applicant-packets', [
            'event_id' => 'pms-release-1',
            'applicant_id' => 'A-PMS-99',
            'revision' => 1,
            'released' => true,
        ]);
        $pmsResponse->assertForbidden();

        // OAS connector with valid applicant packet structure succeeds
        $docId = (string) Str::uuid();
        $versionId = (string) Str::uuid();
        Storage::disk('local')->put('documents/'.$versionId, 'psa-bytes');

        DB::table('documents')->insert([
            'id' => $docId,
            'owner_id' => null,
            'source' => 'OAS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'title' => 'PSA Document',
            'category' => 'PSA_BIRTH_CERTIFICATE',
            'classification' => 'Internal',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('document_versions')->insert([
            'id' => $versionId,
            'document_id' => $docId,
            'number' => 1,
            'filename' => 'psa.pdf',
            'mime' => 'application/pdf',
            'size' => 9,
            'checksum' => hash('sha256', 'psa-bytes'),
            'storage_key' => 'documents/'.$versionId,
            'scan_status' => 'Clean',
            'created_at' => now(),
        ]);

        $oasResponse = $this->withToken('token-OAS')->postJson('/api/v1/applicant-packets', [
            'event_id' => 'oas-release-1',
            'applicant_id' => 'A-OAS-100',
            'revision' => 1,
            'released' => true,
            'admitted' => true,
            'verified' => true,
            'version_ids' => [$versionId],
        ]);
        $oasResponse->assertCreated();
    }
}
