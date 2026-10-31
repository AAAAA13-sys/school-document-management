<?php

namespace Tests\Feature;

use App\Models\Connector;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\ApplicantPacketService;
use App\Services\EsIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class EsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_artisan_dms_connector_command_provisions_es_connector(): void
    {
        $this->artisan('dms:connector', ['source' => 'ES', '--token' => 'es-custom-test-key-12345'])
            ->assertExitCode(0);

        $connector = DB::table('connectors')
            ->where('source', 'ES')
            ->where('school_id', 'PLP')
            ->first();

        $this->assertNotNull($connector);
        $this->assertTrue((bool) $connector->active);
        $this->assertSame(hash('sha256', 'es-custom-test-key-12345'), $connector->token_hash);
    }

    public function test_es_connector_can_read_packet_and_download_files_with_x_api_key(): void
    {
        Storage::fake('local');
        $token = 'es-integration-secret-test-key';
        DB::table('connectors')->insert([
            'name' => 'ES Connector',
            'source' => 'ES',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'token_hash' => hash('sha256', $token),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $oasToken = 'oas-integration-secret-test-key';
        $oasId = DB::table('connectors')->insertGetId([
            'name' => 'OAS Connector',
            'source' => 'OAS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'token_hash' => hash('sha256', $oasToken),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $oasConnector = (object) [
            'id' => $oasId,
            'source' => 'OAS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ];

        $owner = User::factory()->create([
            'role' => 'staff',
            'source' => 'OAS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ]);

        $docId = (string) Str::uuid();
        DB::table('documents')->insert([
            'id' => $docId,
            'owner_id' => $owner->id,
            'school_id' => 'PLP',
            'campus' => 'Main',
            'source' => 'OAS',
            'title' => 'PSA Birth Certificate',
            'classification' => 'Internal',
            'category' => 'PSA',
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $versionId = (string) Str::uuid();
        $storagePath = 'documents/' . $versionId;
        Storage::disk('local')->put($storagePath, '%PDF-1.4 Fake PSA Content');

        DB::table('document_versions')->insert([
            'id' => $versionId,
            'document_id' => $docId,
            'number' => 1,
            'filename' => 'PSA.pdf',
            'storage_key' => $storagePath,
            'mime' => 'application/pdf',
            'size' => 24,
            'checksum' => hash('sha256', '%PDF-1.4 Fake PSA Content'),
            'scan_status' => 'Clean',
            'created_at' => now(),
        ]);

        // OAS publishes packet
        $packetService = app(ApplicantPacketService::class);
        [$packetData, $status] = $packetService->receive($oasConnector, [
            'event_id' => 'release-packet-001',
            'applicant_id' => 'ADM-2026-001',
            'revision' => 1,
            'released' => true,
            'admitted' => true,
            'verified' => true,
            'version_ids' => [$versionId],
        ]);
        $this->assertSame(201, $status);
        $packetId = $packetData['packet_id'];

        // ES reads packet via /api/v1/applicant-packets/{id} with X-API-Key
        $response = $this->withHeader('X-API-Key', $token)
            ->getJson("/api/v1/applicant-packets/{$packetId}");

        $response->assertStatus(200);
        $response->assertJson([
            'packet_id' => $packetId,
            'applicant_id' => 'ADM-2026-001',
            'revision' => 1,
        ]);

        // ES downloads file via /api/v1/applicant-packets/{id}/files/{version}
        $downloadResponse = $this->withHeader('X-API-Key', $token)
            ->get("/api/v1/applicant-packets/{$packetId}/files/{$versionId}");

        $downloadResponse->assertStatus(200);
        $this->assertSame('%PDF-1.4 Fake PSA Content', $downloadResponse->streamedContent());
    }

    public function test_es_integration_service_pushes_students_and_archives_cor(): void
    {
        Storage::fake('local');
        Http::fake([
            'http://es-test.local/api/v1/integrations/admission' => Http::response([
                'received' => 1,
                'created' => 1,
                'updated' => 0,
                'rejected' => 0,
            ], 200),
            'http://es-test.local/api/v1/enrollments/101/cor' => Http::response('%PDF-1.4 Mock COR Document', 200),
        ]);

        $service = new EsIntegrationService('http://es-test.local/api/v1', 'mock-es-token');

        // Test pushing admitted student to ES
        $pushResult = $service->pushAdmittedStudents([
            [
                'admission_id' => 'ADM-2026-001',
                'first_name' => 'Maria',
                'last_name' => 'Santos',
                'email' => 'maria@example.test',
                'program_code' => 'BSIT',
                'year_level' => 1,
                'admission_status' => 'ADMITTED',
            ],
        ], 1);

        $this->assertSame(200, $pushResult['status']);
        $this->assertSame(1, $pushResult['body']['created']);

        // Test archiving COR from ES into DMS
        $staff = User::factory()->create([
            'role' => 'staff',
            'source' => 'ES',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ]);

        $archiveResult = $service->archiveCorDocument($staff, 101, '2026-0001', '1st-Sem-2026');
        $this->assertSame(201, $archiveResult['status']);

        $this->assertDatabaseHas('documents', [
            'source' => 'ES',
            'title' => 'COR - Student 2026-0001 - Period 1st-Sem-2026',
        ]);
    }
}
