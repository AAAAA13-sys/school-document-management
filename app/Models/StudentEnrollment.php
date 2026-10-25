<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudentEnrollment extends Model
{
    use HasFactory;

    protected $table = 'student_enrollments';

    protected $fillable = [
        'student_number',
        'applicant_id',
        'first_name',
        'middle_name',
        'last_name',
        'program',
        'year_level',
        'academic_year',
        'semester',
        'enrolled_units',
        'course_allocations',
        'enrollment_status',
        'clearance_status',
        'cor_document_id',
        'school_id',
        'campus',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'year_level' => 'integer',
            'enrolled_units' => 'integer',
            'course_allocations' => 'array',
            'metadata' => 'array',
        ];
    }

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(StudentApplicant::class, 'applicant_id');
    }

    public function corDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'cor_document_id');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(CashierAssessment::class, 'student_number', 'student_number');
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }
}
