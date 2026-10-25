<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Student Applicants (Admissions Subsystem)
        Schema::create('student_applicants', function (Blueprint $t) {
            $t->id();
            $t->string('applicant_number', 64);
            $t->string('first_name', 100);
            $t->string('middle_name', 100)->nullable();
            $t->string('last_name', 100);
            $t->string('suffix', 20)->nullable();
            $t->string('email', 150);
            $t->string('phone', 50)->nullable();
            $t->string('program_applied', 100);
            $t->string('admission_status', 50)->default('pending');
            $t->string('school_id', 64)->default('PLP');
            $t->string('campus', 64)->default('Main');
            $t->timestamp('applied_at')->nullable();
            $t->json('requirements')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'campus', 'applicant_number']);
            $t->index(['school_id', 'campus', 'admission_status']);
        });

        // 2. Applicant Documents Pivot (Admissions Requirements Document Linking)
        Schema::create('applicant_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('applicant_id')->constrained('student_applicants')->cascadeOnDelete();
            $t->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $t->string('document_type', 100);
            $t->string('verification_status', 50)->default('pending');
            $t->string('verified_by', 100)->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['applicant_id', 'document_type']);
        });

        // 3. Student Enrollments (Enrollment Subsystem)
        Schema::create('student_enrollments', function (Blueprint $t) {
            $t->id();
            $t->string('student_number', 64);
            $t->foreignId('applicant_id')->nullable()->constrained('student_applicants')->nullOnDelete();
            $t->string('first_name', 100);
            $t->string('middle_name', 100)->nullable();
            $t->string('last_name', 100);
            $t->string('program', 100);
            $t->unsignedInteger('year_level')->default(1);
            $t->string('academic_year', 30);
            $t->string('semester', 30);
            $t->unsignedInteger('enrolled_units')->default(0);
            $t->json('course_allocations')->nullable();
            $t->string('enrollment_status', 50)->default('pending');
            $t->string('clearance_status', 50)->default('cleared');
            $t->foreignUuid('cor_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $t->string('school_id', 64)->default('PLP');
            $t->string('campus', 64)->default('Main');
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'campus', 'student_number']);
            $t->index(['school_id', 'campus', 'enrollment_status']);
        });

        // 4. Cashier Assessments & Transactions (Cashier Subsystem)
        Schema::create('cashier_assessments', function (Blueprint $t) {
            $t->id();
            $t->string('assessment_number', 64);
            $t->string('or_number', 64)->nullable();
            $t->string('student_number', 64)->nullable();
            $t->string('applicant_number', 64)->nullable();
            $t->string('student_name', 150);
            $t->string('account_type', 64)->default('tuition');
            $t->string('academic_year', 30)->nullable();
            $t->string('semester', 30)->nullable();
            $t->decimal('total_amount', 12, 2);
            $t->decimal('amount_paid', 12, 2)->default(0.00);
            $t->decimal('balance', 12, 2)->default(0.00);
            $t->string('payment_method', 50)->nullable();
            $t->string('payment_status', 50)->default('assessed');
            $t->foreignUuid('receipt_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->string('verified_by', 100)->nullable();
            $t->string('school_id', 64)->default('PLP');
            $t->string('campus', 64)->default('Main');
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'campus', 'assessment_number']);
            $t->index(['school_id', 'campus', 'payment_status']);
        });

        // 5. Payroll Records & Payslips (Payroll Subsystem)
        Schema::create('payroll_records', function (Blueprint $t) {
            $t->id();
            $t->string('payroll_period', 64);
            $t->string('employee_id', 64);
            $t->string('employee_name', 150);
            $t->string('department', 100);
            $t->string('employment_type', 50)->default('faculty');
            $t->decimal('gross_pay', 12, 2);
            $t->decimal('deductions', 12, 2)->default(0.00);
            $t->decimal('net_pay', 12, 2);
            $t->json('deduction_breakdown')->nullable();
            $t->string('disbursement_status', 50)->default('processed');
            $t->foreignUuid('payslip_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $t->timestamp('disbursed_at')->nullable();
            $t->string('school_id', 64)->default('PLP');
            $t->string('campus', 64)->default('Main');
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->unique(['school_id', 'campus', 'payroll_period', 'employee_id']);
            $t->index(['school_id', 'campus', 'payroll_period']);
        });

        // 6. Metadata and Tagging Columns for Universal Documents Repository
        Schema::table('documents', function (Blueprint $t) {
            if (! Schema::hasColumn('documents', 'tags')) {
                $t->json('tags')->nullable()->after('classification');
            }
            if (! Schema::hasColumn('documents', 'metadata')) {
                $t->json('metadata')->nullable()->after('tags');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_records');
        Schema::dropIfExists('cashier_assessments');
        Schema::dropIfExists('student_enrollments');
        Schema::dropIfExists('applicant_documents');
        Schema::dropIfExists('student_applicants');
        Schema::table('documents', function (Blueprint $t) {
            if (Schema::hasColumn('documents', 'metadata')) {
                $t->dropColumn('metadata');
            }
            if (Schema::hasColumn('documents', 'tags')) {
                $t->dropColumn('tags');
            }
        });
    }
};
