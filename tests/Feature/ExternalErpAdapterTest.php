<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExternalErpAdapterTest extends TestCase
{
    use RefreshDatabase;

    private string $oasApiKey = 'oas-secret-key-123';
    private object $oasConnector;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        // Provision OAS connector
        $id = DB::table('connectors')->insertGetId([
            'name' => 'OAS Connector',
            'source' => 'OAS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'token_hash' => hash('sha256', $this->oasApiKey),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->oasConnector = DB::table('connectors')->where('id', $id)->first();
    }

    public function test_admission_http_client_can_put_applicant_and_post_events_with_x_api_key(): void
    {
        $ref = 'AD-2026-00001';
        $eventId = (string) Str::uuid();

        // 1. PUT /api/applicants/{ref} using X-API-Key & Idempotency-Key
        $putResponse = $this->withHeaders([
            'X-API-Key' => $this->oasApiKey,
            'Idempotency-Key' => $eventId,
        ])->putJson('/api/applicants/'.$ref, [
            'applicant_ref' => $ref,
            'status' => 'admitted',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'lrn' => '123456789012',
            'student_number' => '2026-0001',
        ]);

        $putResponse->assertStatus(201)
            ->assertJsonPath('applied', true)
            ->assertJsonPath('applicant_ref', $ref);

        $this->assertDatabaseHas('source_snapshots', [
            'source' => 'OAS',
            'record_type' => 'applicant',
            'record_id' => $ref,
        ]);

        // 2. Idempotent replay returns 200
        $replayResponse = $this->withHeaders([
            'X-API-Key' => $this->oasApiKey,
            'Idempotency-Key' => $eventId,
        ])->putJson('/api/applicants/'.$ref, [
            'applicant_ref' => $ref,
            'status' => 'admitted',
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'lrn' => '123456789012',
            'student_number' => '2026-0001',
        ]);
        $replayResponse->assertOk();

        // 3. POST /api/applicants/{ref}/events
        $eventUuid = (string) Str::uuid();
        $eventResponse = $this->withHeaders([
            'X-API-Key' => $this->oasApiKey,
            'Idempotency-Key' => $eventUuid,
        ])->postJson('/api/applicants/'.$ref.'/events', [
            'type' => 'admission.completed',
            'occurred_at' => now()->toIso8601String(),
            'data' => ['program' => 'BSIT'],
        ]);

        $eventResponse->assertStatus(201)
            ->assertJsonPath('applied', true)
            ->assertJsonPath('event_id', $eventUuid);

        // Replay of the same event returns 200
        $eventReplay = $this->withHeaders([
            'X-API-Key' => $this->oasApiKey,
            'Idempotency-Key' => $eventUuid,
        ])->postJson('/api/applicants/'.$ref.'/events', [
            'type' => 'admission.completed',
            'occurred_at' => now()->toIso8601String(),
            'data' => ['program' => 'BSIT'],
        ]);
        $eventReplay->assertOk();

        $this->assertDatabaseHas('history_receipts', [
            'source' => 'OAS',
            'event_id' => $eventUuid,
        ]);
    }

    public function test_admission_dms_document_store_flow_with_download_url(): void
    {
        $ref = 'AD-2026-00001';
        $file = UploadedFile::fake()->createWithContent('psa_birth_certificate.pdf', 'dummy content 1');
        $checksum = hash_file('sha256', $file->getRealPath());
        $idempotencyKey = hash('sha256', $ref.'|PSA|'.$checksum);

        // 1. Initial upload via POST /api/documents
        $uploadResponse = $this->withHeaders([
            'X-API-Key' => $this->oasApiKey,
            'Idempotency-Key' => $idempotencyKey,
        ])->post('/api/documents', [
            'source' => 'admission',
            'applicant_ref' => $ref,
            'category' => 'PSA',
            'confidential' => '1',
            'sha256' => $checksum,
            'file' => $file,
        ]);

        $uploadResponse->assertCreated();
        $docId = $uploadResponse->json('id');
        $this->assertNotNull($docId);
        $this->assertSame($docId, $uploadResponse->json('document_id'));

        // Mark the version as Clean for download test
        DB::table('document_versions')->where('document_id', $docId)->update(['scan_status' => 'Clean']);

        // 2. Replacement upload via POST /api/documents/{id}/versions
        $newFile = UploadedFile::fake()->createWithContent('psa_updated.pdf', 'dummy content 2');
        $newChecksum = hash_file('sha256', $newFile->getRealPath());
        $newKey = hash('sha256', $ref.'|PSA|'.$newChecksum);

        $versionResponse = $this->withHeaders([
            'X-API-Key' => $this->oasApiKey,
            'Idempotency-Key' => $newKey,
        ])->post('/api/documents/'.$docId.'/versions', [
            'source' => 'admission',
            'applicant_ref' => $ref,
            'category' => 'PSA',
            'confidential' => '1',
            'sha256' => $newChecksum,
            'file' => $newFile,
        ]);

        $versionResponse->assertCreated();
        $this->assertSame($docId, $versionResponse->json('id'));

        // Mark the new version as Clean
        DB::table('document_versions')->where('document_id', $docId)->where('checksum', $newChecksum)->update(['scan_status' => 'Clean']);

        // 3. Obtain signed download URL via GET /api/documents/{id}/download-url
        $urlResponse = $this->withHeaders([
            'X-API-Key' => $this->oasApiKey,
        ])->getJson('/api/documents/'.$docId.'/download-url');

        $urlResponse->assertOk()->assertJsonStructure(['url']);
        $downloadUrl = $urlResponse->json('url');
        $this->assertNotEmpty($downloadUrl);

        // 4. Stream the file using the signed URL without credentials
        $streamResponse = $this->get($downloadUrl);
        $streamResponse->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }
}
