<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashierAssessment extends Model
{
    use HasFactory;

    protected $table = 'cashier_assessments';

    protected $fillable = [
        'assessment_number',
        'or_number',
        'student_number',
        'applicant_number',
        'student_name',
        'account_type',
        'academic_year',
        'semester',
        'total_amount',
        'amount_paid',
        'balance',
        'payment_method',
        'payment_status',
        'receipt_document_id',
        'verified_at',
        'verified_by',
        'school_id',
        'campus',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance' => 'decimal:2',
            'verified_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function receiptDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'receipt_document_id');
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_number', 'student_number');
    }

    public function isVerified(): bool
    {
        return strtolower((string) $this->payment_status) === 'verified';
    }
}
