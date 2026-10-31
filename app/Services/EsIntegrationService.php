<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EsIntegrationService
{
    protected string $baseUrl;
    protected string $token;

    public function __construct(?string $baseUrl = null, ?string $token = null)
    {
        $this->baseUrl = rtrim($baseUrl ?: (string) config('services.es.url', 'http://127.0.0.1:8001/api/v1'), '/');
        $this->token = $token ?: (string) config('services.es.token', '');
    }

    /**
     * Test HTTP connectivity to the Enrollment Management System (ES).
     */
    public function ping(): bool
    {
        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->withToken($this->token)
                ->get("{$this->baseUrl}/students");

            return $response->status() !== 404 && $response->status() !== 500;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Push admitted applicant records to ES (/api/v1/integrations/admission).
     *
     * Matches the ES Admission file/envelope format specified in ES API docs:
     * - source: "admission"
     * - schema_version: "1.0"
     * - curriculum_id: int
     * - students: [ { admission_id, first_name, last_name, email, program_code, year_level, admission_status } ]
     */
    public function pushAdmittedStudents(array $students, int $curriculumId = 1): array
    {
        $payload = [
            'source' => 'admission',
            'schema_version' => '1.0',
            'curriculum_id' => $curriculumId,
            'students' => $students,
        ];

        $response = Http::timeout(15)
            ->acceptJson()
            ->withToken($this->token)
            ->post("{$this->baseUrl}/integrations/admission", $payload);

        return [
            'status' => $response->status(),
            'body' => $response->json() ?: $response->body(),
        ];
    }

    /**
     * Fetch the Certificate of Registration (COR) PDF from ES.
     */
    public function fetchCorPdf(int $enrollmentId): ?string
    {
        $response = Http::timeout(15)
            ->withToken($this->token)
            ->get("{$this->baseUrl}/enrollments/{$enrollmentId}/cor");

        if ($response->successful()) {
            return $response->body();
        }

        return null;
    }

    /**
     * Ingest and archive an enrollment COR PDF into DMS document management.
     */
    public function archiveCorDocument(User $actor, int $enrollmentId, string $studentNumber, string $period, ?string $pdfBytes = null): array
    {
        $content = $pdfBytes ?: $this->fetchCorPdf($enrollmentId);
        abort_unless($content, 422, 'COR content could not be retrieved from ES.');

        $tmpFile = tempnam(sys_get_temp_dir(), 'cor_');
        file_put_contents($tmpFile, $content);

        try {
            $file = new UploadedFile(
                $tmpFile,
                "COR-{$studentNumber}-{$period}.pdf",
                'application/pdf',
                null,
                true
            );

            $documentService = app(DocumentService::class);
            [$response, $status] = $documentService->upload($actor, [
                'title' => "COR - Student {$studentNumber} - Period {$period}",
                'source' => 'ES',
                'classification' => 'Internal',
                'description' => "Archived Certificate of Registration for student {$studentNumber} (Period {$period}).",
            ], $file);

            return [
                'status' => $status,
                'document' => $response,
            ];
        } finally {
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
        }
    }
}
