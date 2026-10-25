<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class StudentApplicant extends Model
{
    use HasFactory;

    protected $table = 'student_applicants';

    protected $fillable = [
        'applicant_number',
        'first_name',
        'middle_name',
        'last_name',
        'suffix',
        'email',
        'phone',
        'program_applied',
        'admission_status',
        'school_id',
        'campus',
        'applied_at',
        'requirements',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'applied_at' => 'datetime',
            'requirements' => 'array',
            'metadata' => 'array',
        ];
    }

    public function applicantDocuments(): HasMany
    {
        return $this->hasMany(ApplicantDocument::class, 'applicant_id');
    }

    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'applicant_documents', 'applicant_id', 'document_id')
            ->withPivot(['document_type', 'verification_status', 'verified_by', 'verified_at', 'notes'])
            ->withTimestamps();
    }

    public function enrollment(): HasOne
    {
        return $this->hasOne(StudentEnrollment::class, 'applicant_id');
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name} {$this->suffix}");
    }

    public function isAdmitted(): bool
    {
        return strtolower((string) $this->admission_status) === 'admitted';
    }
}
