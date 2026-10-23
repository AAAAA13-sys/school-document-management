<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DocumentType extends Model
{
    use HasFactory;

    protected $table = 'document_types';

    protected $fillable = [
        'code',
        'name',
        'workspace',
        'classification',
        'description',
    ];

    /**
     * OAS Applicant Documents (admitted applicant packet custody).
     */
    public const OAS_TYPES = [
        'PSA' => 'PSA Birth Certificate',
        'FORM_137' => 'Form 137 / Academic Record',
        'VALID_ID' => 'Valid ID / Identification',
    ];

    /**
     * Internal Office Documents (internal school office workspaces).
     */
    public const INTERNAL_TYPES = [
        'MINUTES' => 'Meeting Minutes',
        'POLICY_DRAFT' => 'Policy Draft',
    ];

    /**
     * Prohibited transactional ERP document types (must remain in PMS, EMS, and ES custody).
     */
    public const PROHIBITED_TYPES = [
        'PAYSLIP',
        'PAYROLL_REGISTER',
        'TAX_FORM',
        'LEAVE_APPLICATION',
        '201_FILE',
        'SERVICE_RECORD',
        'ENROLLMENT_FORM',
        'GRADE_SHEET',
        'CLASS_SCHEDULE',
    ];

    /**
     * Returns all approved registry types (strictly OAS applicant and Internal Office).
     *
     * @return array<string, string>
     */
    public static function allowedTypes(): array
    {
        return self::OAS_TYPES + self::INTERNAL_TYPES;
    }

    /**
     * Check if a type code belongs to the allowed OAS applicant documents.
     */
    public static function isOasType(string $code): bool
    {
        $normalized = strtoupper(trim(str_replace([' ', '-'], '_', $code)));
        return array_key_exists($normalized, self::OAS_TYPES)
            || in_array($normalized, ['PSA', 'FORM_137', 'FORM_138', 'ID', 'VALID_ID', 'PARENT_ID', 'APPLICANT_ID'], true);
    }

    /**
     * Check if a type code is an allowed registry document type.
     */
    public static function isAllowed(string $code): bool
    {
        $normalized = strtoupper(trim(str_replace([' ', '-'], '_', $code)));
        return self::isOasType($normalized) || array_key_exists($normalized, self::INTERNAL_TYPES);
    }

    /**
     * Scope query to OAS applicant types.
     */
    public function scopeOas(Builder $query): Builder
    {
        return $query->where('workspace', 'OAS');
    }

    /**
     * Scope query to Internal Office types.
     */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('workspace', 'INTERNAL');
    }
}
