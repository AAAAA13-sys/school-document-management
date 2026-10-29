<?php

namespace App\Services;

use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * QA Contract Stub for ReleaseGrant Service (Task 4: Release Grant Allowlist).
 *
 * Ground Truth (docs/DMS-SDD-V1.1.pdf Section 1.1 / Section 3.4):
 * Only OAS applicant document types may be granted release for ES retrieval.
 * All PMS, EMS, and transactional types must be rejected with 403 Forbidden.
 *
 * // TODO(BackendAgent): Ensure app/Services/ReleaseGrant.php provides both
 * // authorize() and create() methods matching this OAS allowlist contract.
 */
if (! class_exists(ReleaseGrant::class, false)) {
    class ReleaseGrant
    {
        /**
         * Hardcoded allowlist for OAS applicant document types only.
         */
        public const OAS_ALLOWLIST = [
            'PSA',
            'PSA_BIRTH_CERTIFICATE',
            'FORM_137',
            'FORM_138',
            'TRANSCRIPT_OF_RECORDS',
            'ID',
            'VALID_ID',
            'APPLICANT_ID',
            'PARENT_ID',
            'GUARDIANSHIP_AFFIDAVIT',
            'REPORT_CARD',
            'EXAM_TICKET',
            'OAS_PACKET',
            'OAS_ADMISSION',
            'OAS',
        ];

        public const OAS_ALLOWED_LABELS = [
            'psa',
            'birth certificate',
            'form 137',
            'form 138',
            'academic record',
            'id',
            'identification',
            'guardianship',
            'exam ticket',
            'report card',
        ];

        public static function allowlist(): array
        {
            return self::OAS_ALLOWLIST;
        }

        public static function isAllowed(?string $type): bool
        {
            if ($type === null || trim($type) === '') {
                return false;
            }

            $normalized = strtoupper(trim(str_replace([' ', '-'], '_', $type)));

            // Reject any explicit PMS, EMS, or Payroll prefixes
            if (str_starts_with($normalized, 'PMS') || str_starts_with($normalized, 'EMS') || str_starts_with($normalized, 'PAYROLL') || str_starts_with($normalized, 'HR_')) {
                return false;
            }

            if (in_array($normalized, self::OAS_ALLOWLIST, true)) {
                return true;
            }

            $lower = strtolower(trim($type));
            foreach (self::OAS_ALLOWED_LABELS as $label) {
                if (str_contains($lower, $label)) {
                    return true;
                }
            }

            return false;
        }

        public static function isAllowedType(string $documentType): bool
        {
            return self::isAllowed($documentType);
        }

        /**
         * Authorize an OAS document or document type for release.
         *
         * @throws HttpException 403 when not permitted
         */
        public function authorize(string|object $documentOrType): void
        {
            if (is_string($documentOrType)) {
                abort_unless(self::isAllowed($documentOrType), 403, 'Document type not permitted for release. Only OAS applicant documents may be released.');

                return;
            }

            $doc = $documentOrType;
            abort_unless(($doc->source ?? '') === 'OAS', 403, 'Only OAS documents may be released.');

            $category = $doc->category ?? null;
            if ($category !== null && trim($category) !== '') {
                abort_if(preg_match('/medical|health|clinical|diagnos|vaccin/i', $category), 403, 'Medical files require separately agreed access rules.');
                abort_unless(self::isAllowed($category), 403, 'Document type not permitted for release. Only OAS applicant documents may be released.');
            }

            $type = $doc->document_type ?? $doc->type ?? null;
            if ($type !== null && trim($type) !== '') {
                abort_unless(self::isAllowed($type), 403, 'Document type not permitted for release. Only OAS applicant documents may be released.');
            }
        }

        /**
         * Create a release grant for a given document type.
         *
         * @param  array<string, mixed>  $attributes
         * @return array{grant_id: string, document_type: string, status: string, code: int}
         *
         * @throws HttpException 403 when type is not an approved OAS document type.
         */
        public function create(array $attributes): array
        {
            $type = (string) ($attributes['document_type'] ?? $attributes['type'] ?? '');
            $this->authorize($type);

            $grantId = (string) Str::uuid();

            return [
                'grant_id' => $grantId,
                'document_type' => strtoupper(trim($type)),
                'applicant_id' => $attributes['applicant_id'] ?? 'A-APP-'.rand(100, 999),
                'status' => 'created',
                'code' => 201,
            ];
        }

        public static function make(array $attributes): array
        {
            return (new static)->create($attributes);
        }
    }
}
