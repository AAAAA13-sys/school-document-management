<?php

namespace App\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ReleaseGrant
{
    /**
     * Hardcoded allowlist for OAS applicant document types only.
     * Strictly limited to OAS applicant documents (PSA, Form 137, IDs).
     */
    public const OAS_ALLOWLIST = [
        'PSA',
        'PSA_BIRTH_CERTIFICATE',
        'FORM_137',
        'FORM_138',
        'ID',
        'VALID_ID',
        'APPLICANT_ID',
        'PARENT_ID',
        'GUARDIANSHIP_AFFIDAVIT',
        'EXAM_TICKET',
    ];

    /**
     * Case-insensitive substring match labels for allowed OAS applicant types.
     */
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
    ];

    /**
     * Returns the hardcoded OAS allowlist.
     *
     * @return array<int, string>
     */
    public static function allowlist(): array
    {
        return self::OAS_ALLOWLIST;
    }

    /**
     * Check if a type code or category is an authorized OAS applicant document type.
     */
    public static function isAllowed(?string $type): bool
    {
        if ($type === null || trim($type) === '') {
            return false;
        }

        $normalized = strtoupper(trim(str_replace([' ', '-'], '_', $type)));
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

    /**
     * Authorize an OAS document or document type for release.
     * Hardcoded allowlist: only OAS document types permitted.
     * Rejects other types with 403 Forbidden.
     *
     * @throws HttpException
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
}
