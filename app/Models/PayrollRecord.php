<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRecord extends Model
{
    use HasFactory;

    protected $table = 'payroll_records';

    protected $fillable = [
        'payroll_period',
        'employee_id',
        'employee_name',
        'department',
        'employment_type',
        'gross_pay',
        'deductions',
        'net_pay',
        'deduction_breakdown',
        'disbursement_status',
        'payslip_document_id',
        'disbursed_at',
        'school_id',
        'campus',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'gross_pay' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net_pay' => 'decimal:2',
            'deduction_breakdown' => 'array',
            'disbursed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function payslipDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'payslip_document_id');
    }

    public function isDisbursed(): bool
    {
        return strtolower((string) $this->disbursement_status) === 'disbursed';
    }
}
