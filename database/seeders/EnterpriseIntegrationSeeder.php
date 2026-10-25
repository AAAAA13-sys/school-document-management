<?php

namespace Database\Seeders;

use App\Models\CashierAssessment;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\PayrollRecord;
use App\Models\StudentApplicant;
use App\Models\StudentEnrollment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnterpriseIntegrationSeeder extends Seeder
{
    public function run(): void
    {
        $school = 'PLP';
        $campus = 'Main';

        // 1. Seed Subsystem Connectors with known tokens for Postman and Load Testing
        $connectors = [
            ['name' => 'OAS Admission Gateway', 'source' => 'OAS', 'token' => 'dms-oas-test-token-2026'],
            ['name' => 'ES Enrollment Gateway', 'source' => 'ES', 'token' => 'dms-es-test-token-2026'],
            ['name' => 'Cashier Subsystem Gateway', 'source' => 'CASHIER', 'token' => 'dms-cashier-test-token-2026'],
            ['name' => 'PMS Payroll Gateway', 'source' => 'PMS', 'token' => 'dms-pms-test-token-2026'],
            ['name' => 'EMS Employee Gateway', 'source' => 'EMS', 'token' => 'dms-ems-test-token-2026'],
            ['name' => 'Enterprise Master Gateway', 'source' => 'EMS', 'token' => 'dms-master-integration-token-2026'],
        ];

        foreach ($connectors as $c) {
            DB::table('connectors')->updateOrInsert(
                [
                    'school_id' => $school,
                    'campus' => $campus,
                    'name' => $c['name'],
                ],
                [
                    'source' => $c['source'],
                    'token_hash' => hash('sha256', $c['token']),
                    'active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        // 2. Seed Sample Clean Documents in Private Storage
        $docId1 = (string) Str::uuid();
        $verId1 = (string) Str::uuid();
        Document::updateOrInsert(
            ['id' => $docId1],
            [
                'source' => 'OAS',
                'school_id' => $school,
                'campus' => $campus,
                'title' => 'Form 137 Transcript of Records - Juan Rizal',
                'subject' => 'Admission Requirements',
                'reference' => 'DOC-FORM137-001',
                'category' => 'Admission',
                'classification' => 'Confidential',
                'tags' => json_encode(['admission', 'transcript', 'deped']),
                'metadata' => json_encode(['verified_deped' => true]),
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DocumentVersion::updateOrInsert(
            ['id' => $verId1],
            [
                'document_id' => $docId1,
                'number' => 1,
                'filename' => 'form137_juan_rizal.pdf',
                'mime' => 'application/pdf',
                'size' => 102400,
                'checksum' => hash('sha256', 'dummy-content-1'),
                'storage_key' => 'documents/'.$verId1,
                'scan_status' => 'Clean',
                'created_at' => now(),
            ]
        );

        $docId2 = (string) Str::uuid();
        $verId2 = (string) Str::uuid();
        Document::updateOrInsert(
            ['id' => $docId2],
            [
                'source' => 'ES',
                'school_id' => $school,
                'campus' => $campus,
                'title' => 'Certificate of Registration 2026-101',
                'subject' => 'Official COR',
                'reference' => 'COR-2026-001',
                'category' => 'Enrollment',
                'classification' => 'Internal',
                'tags' => json_encode(['enrollment', 'cor', '1st-sem']),
                'metadata' => json_encode(['semester' => '1st Semester 2026-2027']),
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DocumentVersion::updateOrInsert(
            ['id' => $verId2],
            [
                'document_id' => $docId2,
                'number' => 1,
                'filename' => 'cor_juan_rizal_2026.pdf',
                'mime' => 'application/pdf',
                'size' => 84200,
                'checksum' => hash('sha256', 'dummy-content-2'),
                'storage_key' => 'documents/'.$verId2,
                'scan_status' => 'Clean',
                'created_at' => now(),
            ]
        );

        $docId3 = (string) Str::uuid();
        $verId3 = (string) Str::uuid();
        Document::updateOrInsert(
            ['id' => $docId3],
            [
                'source' => 'CASHIER',
                'school_id' => $school,
                'campus' => $campus,
                'title' => 'Official Receipt - Tuition Payment 2026-001',
                'subject' => 'Cashier Receipts',
                'reference' => 'OR-2026-99001',
                'category' => 'Financial',
                'classification' => 'Internal',
                'tags' => json_encode(['cashier', 'receipt', 'tuition']),
                'metadata' => json_encode(['amount' => 15000]),
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DocumentVersion::updateOrInsert(
            ['id' => $verId3],
            [
                'document_id' => $docId3,
                'number' => 1,
                'filename' => 'receipt_or99001.pdf',
                'mime' => 'application/pdf',
                'size' => 64500,
                'checksum' => hash('sha256', 'dummy-content-3'),
                'storage_key' => 'documents/'.$verId3,
                'scan_status' => 'Clean',
                'created_at' => now(),
            ]
        );

        $docId4 = (string) Str::uuid();
        $verId4 = (string) Str::uuid();
        Document::updateOrInsert(
            ['id' => $docId4],
            [
                'source' => 'PMS',
                'school_id' => $school,
                'campus' => $campus,
                'title' => 'Faculty Payslip - October 2026 - Maria Santos',
                'subject' => 'Salary Slips',
                'reference' => 'PAYSLIP-2026-10-001',
                'category' => 'Payroll',
                'classification' => 'Restricted',
                'tags' => json_encode(['payroll', 'payslip', 'faculty']),
                'metadata' => json_encode(['period' => '2026-10-A']),
                'revision' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
        DocumentVersion::updateOrInsert(
            ['id' => $verId4],
            [
                'document_id' => $docId4,
                'number' => 1,
                'filename' => 'payslip_emp10001_oct2026.pdf',
                'mime' => 'application/pdf',
                'size' => 52300,
                'checksum' => hash('sha256', 'dummy-content-4'),
                'storage_key' => 'documents/'.$verId4,
                'scan_status' => 'Clean',
                'created_at' => now(),
            ]
        );

        // 3. Seed Sample Student Applicants
        $app1 = StudentApplicant::updateOrCreate(
            ['applicant_number' => 'APP-2026-1001', 'school_id' => $school, 'campus' => $campus],
            [
                'first_name' => 'Juan',
                'middle_name' => 'Protacio',
                'last_name' => 'Rizal',
                'suffix' => null,
                'email' => 'juan.rizal@plp.edu.ph',
                'phone' => '09181234567',
                'program_applied' => 'BS Information Technology',
                'admission_status' => 'admitted',
                'applied_at' => now()->subDays(5),
                'requirements' => [
                    ['type' => 'FORM_137', 'document_id' => $docId1, 'status' => 'verified', 'notes' => 'High school records confirmed.'],
                ],
                'metadata' => ['gpa' => 95.0, 'track' => 'STEM'],
            ]
        );

        DB::table('applicant_documents')->updateOrInsert(
            ['applicant_id' => $app1->id, 'document_type' => 'FORM_137'],
            [
                'document_id' => $docId1,
                'verification_status' => 'verified',
                'verified_by' => 'OAS Officer',
                'verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        StudentApplicant::updateOrCreate(
            ['applicant_number' => 'APP-2026-1002', 'school_id' => $school, 'campus' => $campus],
            [
                'first_name' => 'Andres',
                'middle_name' => 'Castro',
                'last_name' => 'Bonifacio',
                'suffix' => null,
                'email' => 'andres.bonifacio@plp.edu.ph',
                'phone' => '09191234568',
                'program_applied' => 'BS Computer Science',
                'admission_status' => 'under_review',
                'applied_at' => now()->subDays(2),
                'requirements' => [],
                'metadata' => ['gpa' => 91.5],
            ]
        );

        // 4. Seed Sample Student Enrollments
        StudentEnrollment::updateOrCreate(
            ['student_number' => '2026-10001-MN-0', 'school_id' => $school, 'campus' => $campus],
            [
                'applicant_id' => $app1->id,
                'first_name' => 'Juan',
                'middle_name' => 'Protacio',
                'last_name' => 'Rizal',
                'program' => 'BS Information Technology',
                'year_level' => 1,
                'academic_year' => '2026-2027',
                'semester' => '1st Semester',
                'enrolled_units' => 21,
                'course_allocations' => [
                    ['course_code' => 'IT101', 'description' => 'Intro to IT', 'units' => 3, 'section' => 'IT1A'],
                    ['course_code' => 'IT102', 'description' => 'Computer Programming 1', 'units' => 3, 'section' => 'IT1A'],
                    ['course_code' => 'MATH101', 'description' => 'Discrete Mathematics', 'units' => 3, 'section' => 'IT1A'],
                ],
                'enrollment_status' => 'enrolled',
                'clearance_status' => 'cleared',
                'cor_document_id' => $docId2,
            ]
        );

        // 5. Seed Sample Cashier Assessments
        CashierAssessment::updateOrCreate(
            ['assessment_number' => 'ASSESS-2026-1001', 'school_id' => $school, 'campus' => $campus],
            [
                'or_number' => 'OR-2026-99001',
                'student_number' => '2026-10001-MN-0',
                'student_name' => 'Juan Rizal',
                'account_type' => 'tuition',
                'academic_year' => '2026-2027',
                'semester' => '1st Semester',
                'total_amount' => 15000.00,
                'amount_paid' => 15000.00,
                'balance' => 0.00,
                'payment_method' => 'online_transfer',
                'payment_status' => 'verified',
                'receipt_document_id' => $docId3,
                'verified_at' => now(),
                'verified_by' => 'Cashier Teller 1',
            ]
        );

        CashierAssessment::updateOrCreate(
            ['assessment_number' => 'ASSESS-2026-1002', 'school_id' => $school, 'campus' => $campus],
            [
                'student_number' => '2026-10001-MN-0',
                'student_name' => 'Juan Rizal',
                'account_type' => 'laboratory_fee',
                'academic_year' => '2026-2027',
                'semester' => '1st Semester',
                'total_amount' => 3500.00,
                'amount_paid' => 0.00,
                'balance' => 3500.00,
                'payment_status' => 'assessed',
            ]
        );

        // 6. Seed Sample Payroll Records
        PayrollRecord::updateOrCreate(
            [
                'payroll_period' => '2026-10-A',
                'employee_id' => 'EMP-10001',
                'school_id' => $school,
                'campus' => $campus,
            ],
            [
                'employee_name' => 'Prof. Maria Santos',
                'department' => 'College of Computer Studies',
                'employment_type' => 'faculty',
                'gross_pay' => 52000.00,
                'deductions' => 6200.00,
                'net_pay' => 45800.00,
                'deduction_breakdown' => [
                    'withholding_tax' => 3200.00,
                    'gsis' => 2000.00,
                    'philhealth' => 600.00,
                    'pagibig' => 400.00,
                ],
                'disbursement_status' => 'disbursed',
                'payslip_document_id' => $docId4,
                'disbursed_at' => now(),
            ]
        );

        $this->command?->info('Seeded Enterprise Integration connectors, documents, applicants, enrollments, assessments, and payroll records.');
    }
}
