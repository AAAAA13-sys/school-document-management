<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Scanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'edge-case-connector-token';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->mock(Scanner::class, fn ($m) => $m->shouldReceive('scan')->andReturn('Clean'));

        DB::table('connectors')->insert([
            'name' => 'Edge Case EMS Connector',
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'token_hash' => hash('sha256', $this->token),
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create($attributes + [
            'role' => 'admin',
            'source' => 'EMS',
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ]);
    }

    public function test_web_single_upload_boundary_accepts_10mb_and_rejects_oversized_file(): void
    {
        $actor = $this->user();

        // 1. Boundary: Exactly 10240 KB (10 MB) must succeed
        $exactFile = UploadedFile::fake()->create('exact10mb.pdf', 10240, 'application/pdf');
        $respExact = $this->actingAs($actor)->postJson('/documents', [
            'title' => 'Exact 10MB Document',
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'exact-10mb-test',
            'file' => $exactFile,
        ]);
        $respExact->assertCreated()->assertJsonPath('scan_status', 'Pending');
        $this->assertDatabaseHas('documents', ['title' => 'Exact 10MB Document']);

        // 2. Boundary: 10241 KB (> 10 MB limit) must fail validation with 422
        $oversizedFile = UploadedFile::fake()->create('oversized_10241.pdf', 10241, 'application/pdf');
        $respOversized = $this->actingAs($actor)->postJson('/documents', [
            'title' => 'Oversized 10241KB Document',
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'oversized-10241-test',
            'file' => $oversizedFile,
        ]);
        $respOversized->assertStatus(422)->assertJsonValidationErrors('file');

        // 3. Grossly oversized file (20 MB = 20480 KB) must fail validation with 422
        $hugeFile = UploadedFile::fake()->create('huge_20mb.pdf', 20480, 'application/pdf');
        $respHuge = $this->actingAs($actor)->postJson('/documents', [
            'title' => 'Huge 20MB Document',
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'huge-20mb-test',
            'file' => $hugeFile,
        ]);
        $respHuge->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_web_version_upload_rejects_oversized_file(): void
    {
        $actor = $this->user();

        // Create base document
        $baseFile = UploadedFile::fake()->create('base_v1.pdf', 100, 'application/pdf');
        $resp = $this->actingAs($actor)->postJson('/documents', [
            'title' => 'Base Version Document',
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'base-v1-key',
            'file' => $baseFile,
        ]);
        $resp->assertCreated();
        $docId = $resp->json('document_id');

        // Attempting to upload oversized version (10241 KB) must fail with 422
        $oversizedVersion = UploadedFile::fake()->create('version2_oversized.pdf', 10241, 'application/pdf');
        $respVersion = $this->actingAs($actor)->postJson("/documents/{$docId}/versions", [
            'source' => 'EMS',
            'revision' => 1,
            'submission_key' => 'v2-oversized-key',
            'file' => $oversizedVersion,
        ]);
        $respVersion->assertStatus(422)->assertJsonValidationErrors('file');

        // Document should still have only 1 version
        $this->assertSame(1, DB::table('document_versions')->where('document_id', $docId)->count());
    }

    public function test_api_single_upload_boundary_accepts_10mb_and_rejects_oversized_file(): void
    {
        // 1. Boundary: Exactly 10240 KB upload via connector API must succeed
        $exactFile = UploadedFile::fake()->create('api_exact10mb.pdf', 10240, 'application/pdf');
        $respExact = $this->withToken($this->token)->withHeader('Idempotency-Key', 'api-exact-10mb')->postJson('/api/v1/documents', [
            'title' => 'API Exact 10MB Document',
            'classification' => 'Internal',
            'file' => $exactFile,
        ]);
        $respExact->assertCreated()->assertJsonPath('scan_status', 'Pending');
        $this->assertDatabaseHas('documents', ['title' => 'API Exact 10MB Document']);

        // 2. Boundary: 10241 KB upload via connector API must fail with 422
        $oversizedFile = UploadedFile::fake()->create('api_oversized_10241.pdf', 10241, 'application/pdf');
        $respOversized = $this->withToken($this->token)->withHeader('Idempotency-Key', 'api-oversized-10241')->postJson('/api/v1/documents', [
            'title' => 'API Oversized 10241KB Document',
            'classification' => 'Internal',
            'file' => $oversizedFile,
        ]);
        $respOversized->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_api_version_upload_rejects_oversized_file(): void
    {
        // Upload initial version via API
        $initFile = UploadedFile::fake()->create('api_init.pdf', 200, 'application/pdf');
        $respInit = $this->withToken($this->token)->withHeader('Idempotency-Key', 'api-init-v1')->postJson('/api/v1/documents', [
            'title' => 'API Base Document',
            'classification' => 'Internal',
            'file' => $initFile,
        ]);
        $respInit->assertCreated();
        $docId = $respInit->json('document_id');

        // Attempting oversized version upload via API
        $oversizedFile = UploadedFile::fake()->create('api_v2_oversized.pdf', 10241, 'application/pdf');
        $respVersion = $this->withToken($this->token)->withHeader('Idempotency-Key', 'api-v2-oversized')->postJson("/api/v1/documents/{$docId}/versions", [
            'revision' => 1,
            'file' => $oversizedFile,
        ]);
        $respVersion->assertStatus(422)->assertJsonValidationErrors('file');

        // Only 1 version should exist
        $this->assertSame(1, DB::table('document_versions')->where('document_id', $docId)->count());
    }

    public function test_batch_upload_rejects_oversized_file_in_batch(): void
    {
        $actor = $this->user();
        $normalFile = UploadedFile::fake()->create('file1.pdf', 100, 'application/pdf');
        $oversizedFile = UploadedFile::fake()->create('file2.pdf', 10241, 'application/pdf');

        $response = $this->actingAs($actor)->postJson('/documents/batch', [
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'batch-oversized-test',
            'paths' => ['Folder/file1.pdf', 'Folder/file2.pdf'],
            'files' => [$normalFile, $oversizedFile],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('files.1');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_batch_upload_rejects_batch_exceeding_50_files(): void
    {
        $actor = $this->user();
        $files = [];
        $paths = [];
        for ($i = 0; $i < 51; $i++) {
            $files[] = UploadedFile::fake()->create("doc_{$i}.pdf", 10, 'application/pdf');
            $paths[] = "Bulk/doc_{$i}.pdf";
        }

        $response = $this->actingAs($actor)->postJson('/documents/batch', [
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'batch-51-files-test',
            'paths' => $paths,
            'files' => $files,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('files');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_batch_upload_rejects_path_traversal_attempts_and_malformed_structures(): void
    {
        $actor = $this->user();
        $file = UploadedFile::fake()->create('exploit.pdf', 10, 'application/pdf');

        // 1. Directory traversal using '..'
        $respTraversal = $this->actingAs($actor)->postJson('/documents/batch', [
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'batch-traversal-1',
            'paths' => ['Archive/../../etc/passwd/exploit.pdf'],
            'files' => [$file],
        ]);
        $respTraversal->assertStatus(422)->assertJsonValidationErrors('paths');

        // 2. Path containing colon / Windows absolute path
        $respColon = $this->actingAs($actor)->postJson('/documents/batch', [
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'batch-colon-1',
            'paths' => ['C:/Windows/System32/exploit.pdf'],
            'files' => [$file],
        ]);
        $respColon->assertStatus(422)->assertJsonValidationErrors('paths');

        // 3. Path ending in different filename than the uploaded file
        $respMismatch = $this->actingAs($actor)->postJson('/documents/batch', [
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'batch-mismatch-1',
            'paths' => ['Archive/completely_different_name.pdf'],
            'files' => [$file],
        ]);
        $respMismatch->assertStatus(422)->assertJsonValidationErrors('paths');

        // 4. Path exceeding 11 nested directory levels (total parts > 12)
        $deepPath = implode('/', array_fill(0, 12, 'sub')).'/exploit.pdf';
        $respDeep = $this->actingAs($actor)->postJson('/documents/batch', [
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'batch-deep-1',
            'paths' => [$deepPath],
            'files' => [$file],
        ]);
        $respDeep->assertStatus(422)->assertJsonValidationErrors('paths');

        // 5. Duplicate paths in the same batch
        $fileA = UploadedFile::fake()->create('same.pdf', 10, 'application/pdf');
        $fileB = UploadedFile::fake()->create('same.pdf', 10, 'application/pdf');
        $respDup = $this->actingAs($actor)->postJson('/documents/batch', [
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'batch-dup-paths',
            'paths' => ['Folder/same.pdf', 'Folder/same.pdf'],
            'files' => [$fileA, $fileB],
        ]);
        $respDup->assertStatus(422)->assertJsonValidationErrors('paths');
    }

    public function test_dangerous_and_arbitrary_extensions_stored_safely_as_uuid_without_extension_in_private_store(): void
    {
        $actor = $this->user();

        // Any format intake: extensionless binary, PHP script, executable, shell script, SVG
        $testFiles = [
            ['README', 'Extensionless plain text file', 'text/plain'],
            ['webshell.php', '<?php phpinfo(); ?>', 'text/x-php'],
            ['backdoor.phtml', '<?php echo "bad"; ?>', 'text/html'],
            ['payload.exe', "MZ\x90\x00\x03\x00\x00\x00", 'application/octet-stream'],
            ['script.sh', "#!/bin/bash\nrm -rf /", 'text/x-shellscript'],
            ['vector.svg', '<svg xmlns="http://www.w3.org/2000/svg"><circle r="10"/></svg>', 'image/svg+xml'],
        ];

        foreach ($testFiles as $idx => [$name, $content, $expectedMimeCategory]) {
            $file = UploadedFile::fake()->createWithContent($name, $content);
            $response = $this->actingAs($actor)->postJson('/documents', [
                'title' => "Test file {$name}",
                'source' => 'EMS',
                'classification' => 'Internal',
                'submission_key' => "intake-ext-{$idx}",
                'file' => $file,
            ]);
            $response->assertCreated()->assertJsonPath('scan_status', 'Pending');

            $versionId = $response->json('version_id');
            $docId = $response->json('document_id');

            $versionRow = DB::table('document_versions')->where('id', $versionId)->first();
            $this->assertNotNull($versionRow);
            $this->assertSame($name, $versionRow->filename);

            // Storage key MUST follow 'documents/<UUID>' pattern with NO executable file extension
            $this->assertSame("documents/{$versionId}", $versionRow->storage_key);
            $this->assertTrue(Storage::disk('local')->exists($versionRow->storage_key));

            // Verify file is stored in private storage, NEVER in public web directory
            $fullPath = Storage::disk('local')->path($versionRow->storage_key);
            $this->assertStringNotContainsString('public', $fullPath);
            $this->assertStringEndsWith($versionId, $fullPath); // No extension on disk!
        }
    }

    public function test_filename_directory_traversal_is_sanitized_to_basename_in_database_and_download_headers(): void
    {
        $actor = $this->user();

        // Traversal patterns in original client filename
        $dangerousNames = [
            '../../../../etc/passwd',
            '..\\..\\windows\\system32\\calc.exe',
            '/root/.ssh/id_rsa',
        ];

        foreach ($dangerousNames as $idx => $rawName) {
            $expectedBase = basename(str_replace('\\', '/', $rawName));
            $file = UploadedFile::fake()->createWithContent($rawName, "Sample sensitive fixture bytes {$idx}");

            $response = $this->actingAs($actor)->postJson('/documents', [
                'title' => "Traversal test {$expectedBase}",
                'source' => 'EMS',
                'classification' => 'Internal',
                'submission_key' => "traversal-filename-{$idx}",
                'file' => $file,
            ]);
            $response->assertCreated();

            $versionId = $response->json('version_id');
            $versionRow = DB::table('document_versions')->where('id', $versionId)->first();

            // Filename in DB MUST be stripped to only basename
            $this->assertSame($expectedBase, $versionRow->filename);
            $this->assertStringNotContainsString('..', $versionRow->filename);
            $this->assertStringNotContainsString('/', $versionRow->filename);
            $this->assertStringNotContainsString('\\', $versionRow->filename);

            // Mark version Clean to test download header
            DB::table('document_versions')->where('id', $versionId)->update(['scan_status' => 'Clean']);

            $download = $this->actingAs($actor)->get("/files/{$versionId}");
            $download->assertOk();
            $contentDisposition = $download->headers->get('Content-Disposition');
            $this->assertStringContainsString("filename={$expectedBase}", str_replace('"', '', $contentDisposition));
            $this->assertStringNotContainsString('..', $contentDisposition);
            $this->assertStringNotContainsString('/', $contentDisposition);
        }
    }

    public function test_preview_rejects_non_previewable_extensions_and_mimes_with_415(): void
    {
        $actor = $this->user();

        $nonPreviewFiles = [
            ['program.exe', "MZ\x90\x00\x03", 'application/octet-stream'],
            ['archive.zip', "PK\x03\x04", 'application/zip'],
            ['script.php', '<?php echo 1; ?>', 'text/x-php'],
            ['archive.7z', "7z\xBC\xAF\x27\x1C", 'application/x-7z-compressed'],
            ['music.mp3', "ID3\x03\x00\x00", 'audio/mpeg'],
        ];

        foreach ($nonPreviewFiles as $idx => [$name, $content, $mime]) {
            $file = UploadedFile::fake()->createWithContent($name, $content);
            $upload = $this->actingAs($actor)->postJson('/documents', [
                'title' => "Non preview {$name}",
                'source' => 'EMS',
                'classification' => 'Internal',
                'submission_key' => "non-preview-{$idx}",
                'file' => $file,
            ]);
            $upload->assertCreated();
            $versionId = $upload->json('version_id');

            // Set clean scan status
            DB::table('document_versions')->where('id', $versionId)->update(['scan_status' => 'Clean']);

            // Attempting in-app preview on non-preview format MUST return 415 Unsupported Media Type
            $preview = $this->actingAs($actor)->get("/files/{$versionId}/preview");
            $preview->assertStatus(415);
        }
    }

    public function test_preview_on_previewable_formats_enforces_security_sandbox_and_csp_headers(): void
    {
        $actor = $this->user();

        // 1. PDF preview
        $pdfFile = UploadedFile::fake()->createWithContent('document.pdf', "%PDF-1.4\n%synthetic");
        $uploadPdf = $this->actingAs($actor)->postJson('/documents', [
            'title' => 'Previewable PDF',
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'preview-pdf-key',
            'file' => $pdfFile,
        ]);
        $vPdf = $uploadPdf->json('version_id');
        DB::table('document_versions')->where('id', $vPdf)->update(['scan_status' => 'Clean']);

        $pdfPreview = $this->actingAs($actor)->get("/files/{$vPdf}/preview");
        $pdfPreview->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'");

        // 2. PNG preview (valid 1x1 PNG bytes without needing GD extension)
        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        $pngFile = UploadedFile::fake()->createWithContent('picture.png', $pngBytes);
        $uploadPng = $this->actingAs($actor)->postJson('/documents', [
            'title' => 'Previewable PNG',
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'preview-png-key',
            'file' => $pngFile,
        ]);
        $vPng = $uploadPng->json('version_id');
        DB::table('document_versions')->where('id', $vPng)->update(['scan_status' => 'Clean']);

        $pngPreview = $this->actingAs($actor)->get("/files/{$vPng}/preview");
        $pngPreview->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'");

        // 3. Plain Text preview
        $txtFile = UploadedFile::fake()->createWithContent('notes.txt', 'Hello secure notes');
        $uploadTxt = $this->actingAs($actor)->postJson('/documents', [
            'title' => 'Previewable Text',
            'source' => 'EMS',
            'classification' => 'Internal',
            'submission_key' => 'preview-txt-key',
            'file' => $txtFile,
        ]);
        $vTxt = $uploadTxt->json('version_id');
        DB::table('document_versions')->where('id', $vTxt)->update(['scan_status' => 'Clean']);

        $txtPreview = $this->actingAs($actor)->get("/files/{$vTxt}/preview");
        $txtPreview->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "sandbox; default-src 'none'; style-src 'unsafe-inline'");
    }
}
