<?php

namespace Tests\Unit;

use App\Http\Requests\BatchUploadRequest;
use App\Http\Requests\UploadRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class UploadValidationAndSecurityUnitTest extends TestCase
{
    public function test_upload_request_rules_enforce_exact_10mb_limit(): void
    {
        $rules = (new UploadRequest)->rules();
        $this->assertContains('max:10240', $rules['file']);
        $this->assertContains('required', $rules['file']);
        $this->assertContains('file', $rules['file']);

        // 10240 KB (10 MB exactly) boundary check
        $validFile = UploadedFile::fake()->create('exact10mb.dat', 10240);
        $validatorValid = Validator::make(['file' => $validFile], ['file' => $rules['file']]);
        $this->assertFalse($validatorValid->fails());

        // 10241 KB (> 10 MB limit) boundary check
        $oversizedFile = UploadedFile::fake()->create('oversized.dat', 10241);
        $validatorInvalid = Validator::make(['file' => $oversizedFile], ['file' => $rules['file']]);
        $this->assertTrue($validatorInvalid->fails());
        $this->assertArrayHasKey('file', $validatorInvalid->errors()->toArray());
    }

    public function test_upload_request_rules_require_valid_classification(): void
    {
        $rules = (new UploadRequest)->rules();
        $this->assertContains('in:Internal,Confidential,Restricted', $rules['classification']);

        foreach (['Internal', 'Confidential', 'Restricted'] as $validClassification) {
            $v = Validator::make(['classification' => $validClassification], ['classification' => $rules['classification']]);
            $this->assertFalse($v->fails(), "Classification [{$validClassification}] should be valid.");
        }

        foreach (['Public', 'Secret', 'TopSecret', 'invalid', ''] as $invalidClassification) {
            $v = Validator::make(['classification' => $invalidClassification], ['classification' => $rules['classification']]);
            $this->assertTrue($v->fails(), "Classification [{$invalidClassification}] should be rejected.");
        }
    }

    public function test_upload_request_rules_bound_string_lengths(): void
    {
        $rules = (new UploadRequest)->rules();
        $this->assertContains('max:200', $rules['title']);
        $this->assertStringContainsString('max:200', $rules['subject']);
        $this->assertStringContainsString('max:100', $rules['reference']);
        $this->assertStringContainsString('max:100', $rules['category']);

        $vOverlong = Validator::make([
            'title' => str_repeat('a', 201),
            'subject' => str_repeat('b', 201),
            'reference' => str_repeat('c', 101),
            'category' => str_repeat('d', 101),
        ], [
            'title' => $rules['title'],
            'subject' => $rules['subject'],
            'reference' => $rules['reference'],
            'category' => $rules['category'],
        ]);
        $this->assertTrue($vOverlong->fails());
        $errors = $vOverlong->errors()->toArray();
        $this->assertArrayHasKey('title', $errors);
        $this->assertArrayHasKey('subject', $errors);
        $this->assertArrayHasKey('reference', $errors);
        $this->assertArrayHasKey('category', $errors);
    }

    public function test_batch_upload_rules_enforce_batch_size_limits(): void
    {
        $rules = (new BatchUploadRequest)->rules();
        $this->assertStringContainsString('max:50', $rules['files']);
        $this->assertStringContainsString('min:1', $rules['files']);
        $this->assertContains('max:10240', $rules['files.*']);

        // Empty files array fails min:1
        $vEmpty = Validator::make(['files' => []], ['files' => $rules['files']]);
        $this->assertTrue($vEmpty->fails());

        // Oversized file in files.* fails max:10240
        $vOversized = Validator::make([
            'files' => [
                UploadedFile::fake()->create('file1.pdf', 100),
                UploadedFile::fake()->create('file2.pdf', 10241),
            ],
        ], ['files.*' => $rules['files.*']]);
        $this->assertTrue($vOversized->fails());
        $this->assertArrayHasKey('files.1', $vOversized->errors()->toArray());
    }

    public function test_batch_upload_after_validation_detects_traversal_and_invalid_paths(): void
    {
        $validateBatch = function (array $files, array $paths) {
            $req = BatchUploadRequest::create('/documents/batch', 'POST', [
                'paths' => $paths,
                'source' => 'EMS',
                'classification' => 'Internal',
                'submission_key' => 'batch-key-1',
            ]);
            $req->files->set('files', $files);
            $req->setContainer(app());
            $validator = Validator::make(
                ['files' => $files, 'paths' => $paths, 'source' => 'EMS', 'classification' => 'Internal', 'submission_key' => 'batch-key-1'],
                $req->rules()
            );
            foreach ($req->after() as $after) {
                $validator->after($after);
            }

            return $validator;
        };

        // Path traversal with '..'
        $file = UploadedFile::fake()->create('test.pdf', 10);
        $vTraversal = $validateBatch([$file], ['Archive/../../etc/passwd/test.pdf']);
        $this->assertTrue($vTraversal->fails());
        $this->assertStringContainsString('Folder names must be safe relative paths', implode(' ', $vTraversal->errors()->get('paths')));

        // Path segment with control character or colon
        $vColon = $validateBatch([$file], ['C:/Windows/System32/test.pdf']);
        $this->assertTrue($vColon->fails());

        // Path filename mismatch with actual uploaded file name
        $vMismatch = $validateBatch([$file], ['Folder/different_name.pdf']);
        $this->assertTrue($vMismatch->fails());
        $this->assertStringContainsString('Choose a folder with at most 11 nested levels', implode(' ', $vMismatch->errors()->get('paths')));

        // Path with more than 11 nested directory levels (total parts > 12)
        $deepPath = implode('/', array_fill(0, 12, 'sub')).'/test.pdf'; // 13 parts
        $vDeep = $validateBatch([$file], [$deepPath]);
        $this->assertTrue($vDeep->fails());
        $this->assertStringContainsString('Choose a folder with at most 11 nested levels', implode(' ', $vDeep->errors()->get('paths')));

        // Duplicate paths in batch
        $file1 = UploadedFile::fake()->create('test.pdf', 10);
        $file2 = UploadedFile::fake()->create('test.pdf', 10);
        $vDup = $validateBatch([$file1, $file2], ['Archive/test.pdf', 'Archive/test.pdf']);
        $this->assertTrue($vDup->fails());
        $this->assertStringContainsString('Duplicate paths are not allowed', implode(' ', $vDup->errors()->get('paths')));
    }

    public function test_filename_basename_sanitization_strips_directory_traversal(): void
    {
        $dangerousNames = [
            '../../../../etc/passwd' => 'passwd',
            '..\\..\\Windows\\System32\\cmd.exe' => 'cmd.exe',
            '/var/log/system.log' => 'system.log',
            'C:\\Users\\Admin\\Desktop\\secrets.txt' => 'secrets.txt',
            'nested/folder/path/document.pdf' => 'document.pdf',
            'plain_document.docx' => 'plain_document.docx',
            'archive.tar.gz' => 'archive.tar.gz',
        ];

        foreach ($dangerousNames as $input => $expected) {
            $sanitized = basename(str_replace('\\', '/', $input));
            $this->assertSame($expected, $sanitized, "Sanitizing [{$input}] should produce [{$expected}].");
            $this->assertStringNotContainsString('/', $sanitized);
            $this->assertStringNotContainsString('\\', $sanitized);
            $this->assertStringNotContainsString('..', $sanitized);
        }
    }

    public function test_preview_mimes_whitelist_excludes_executable_and_dangerous_types(): void
    {
        $previewMimes = config('dms.preview_mimes', []);
        $this->assertIsArray($previewMimes);

        // Standard allowed preview formats
        $this->assertContains('application/pdf', $previewMimes);
        $this->assertContains('image/png', $previewMimes);
        $this->assertContains('image/jpeg', $previewMimes);
        $this->assertContains('text/plain', $previewMimes);

        // Executables, scripts, HTML, and arbitrary archives MUST NOT be in preview_mimes
        $dangerousMimes = [
            'application/x-php',
            'text/x-php',
            'application/x-dosexec',
            'application/x-executable',
            'application/x-sh',
            'application/x-bat',
            'application/javascript',
            'text/javascript',
            'text/html',
            'application/zip',
            'application/x-7z-compressed',
            'application/x-rar-compressed',
        ];

        foreach ($dangerousMimes as $mime) {
            $this->assertNotContains($mime, $previewMimes, "MIME [{$mime}] must NOT be in preview_mimes whitelist.");
        }
    }
}
