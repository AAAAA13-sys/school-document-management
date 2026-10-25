<?php

namespace Tests\Feature;

use App\Models\ApplicantDocument;
use App\Models\CashierAssessment;
use App\Models\Document;
use App\Models\PayrollRecord;
use App\Models\StudentApplicant;
use App\Models\StudentEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InterServiceEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected string $oasToken = 'token-oas-test';
    protected string $esToken = 'token-es-test';
    protected string $cashierToken = 'token-cashier-test';
    protected string $pmsToken = 'token-pms-test';
    protected string $emsToken = 'token-ems-test';

    protected function setUp(): void
    {
        parent::setUp();

        // Register connectors for each subsystem
        $connectors = [
            ['name' => 'OAS Subsystem', 'source' => 'OAS', 'token' => $this->oasToken],
            ['name' => 'ES Subsystem', 'source' => 'ES', 'token' => $this->esToken],
            ['name' => 'Cashier Subsystem', 'source' => 'CASHIER', 'token' => $this->cashierToken],
            ['name' => 'PMS Subsystem', 'source' => 'PMS', 'token' => $this->pmsToken],
            ['name' => 'EMS Subsystem', 'source' => 'EMS', 'token' => $this->emsToken],
        ];

        foreach ($connectors as $c) {
            DB::table('connectors')->insert([
                'name' => $c['name'],
                'source' => $c['source'],
                'school_id' => 'PLP',
                'campus' => 'Main',
                'token_hash' => hash('sha256', $c['token']),
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    protected function createDocument(string $token, string $title, string $source = 'OAS'): string
    {
        $file = UploadedFile::fake()->create('sample.pdf', 100, 'application/pdf');
        $resp = $this->withToken($token)
            ->withHeader('Idempotency-Key', 'doc-'.uniqid())
            ->postJson('/api/v1/documents', [
                'title' => $title,
                'classification' => 'Internal',
                'file' => $file,
                'category' => 'Official',
                'tags' => ['official', 'intake'],
                'metadata' => ['department' => 'Registrar'],
            ]);

        $resp->assertCreated();
        return $resp->json('document_id');
    }

    public function test_admissions_endpoint_crud_validation_and_document_linking(): void
    {
        // 1. Unauthenticated request rejected with 401
        $this->postJson('/api/v1/admissions', [])->assertUnauthorized();

        // 2. Unauthorized source connector rejected with 403
        $this->withToken($this->pmsToken)
            ->postJson('/api/v1/admissions', [
                'applicant_number' => 'APP-2026-0001',
                'first_name' => 'Jose',
                'last_name' => 'Rizal',
                'email' => 'jose.rizal@plp.edu.ph',
                'program_applied' => 'BSCS',
            ])->assertForbidden();

        // 3. Validation failure returns 422 with structured errors
        $this->withToken($this->oasToken)
            ->postJson('/api/v1/admissions', [
                'first_name' => 'Jose',
            ])->assertStatus(422)
            ->assertJsonStructure(['status', 'message', 'errors']);

        // 4. Create document to link as requirement
        $docId = $this->createDocument($this->oasToken, 'Form 137 Transcript', 'OAS');

        // 5. Successful single registration returns 201 Created
        $response = $this->withToken($this->oasToken)
            ->postJson('/api/v1/admissions', [
                'applicant_number' => 'APP-2026-0001',
                'first_name' => 'Jose',
                'middle_name' => 'Protacio',
                'last_name' => 'Rizal',
                'email' => 'jose.rizal@plp.edu.ph',
                'phone' => '09181234567',
                'program_applied' => 'BS Information Technology',
                'admission_status' => 'pending',
                'requirements' => [
                    [
                        'type' => 'FORM_137',
                        'document_id' => $docId,
                        'status' => 'verified',
                        'notes' => 'Complete high school record submitted.',
                    ],
                ],
                'metadata' => ['gpa' => 95.5, 'track' => 'STEM'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('created', true)
            ->assertJsonPath('data.applicant_number', 'APP-2026-0001');

        $applicantId = $response->json('data.id');

        // Verify relational link in applicant_documents table
        $this->assertDatabaseHas('applicant_documents', [
            'applicant_id' => $applicantId,
            'document_id' => $docId,
            'document_type' => 'FORM_137',
            'verification_status' => 'verified',
        ]);

        // 6. Idempotent re-submission returns 200 OK with updated content
        $this->withToken($this->oasToken)
            ->postJson('/api/v1/admissions', [
                'applicant_number' => 'APP-2026-0001',
                'first_name' => 'Jose',
                'last_name' => 'Rizal',
                'email' => 'jose.rizal@plp.edu.ph',
                'program_applied' => 'BS Computer Science', // Updated program
                'admission_status' => 'under_review',
            ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('created', false)
            ->assertJsonPath('data.program_applied', 'BS Computer Science');

        // 7. GET list with filters and search
        $this->withToken($this->oasToken)
            ->getJson('/api/v1/admissions?search=Rizal&program=Computer')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'data');

        // 8. GET show by ID or applicant number
        $this->withToken($this->oasToken)
            ->getJson('/api/v1/admissions/APP-2026-0001')
            ->assertOk()
            ->assertJsonPath('data.applicant_number', 'APP-2026-0001');

        // 9. PATCH update status to admitted
        $this->withToken($this->oasToken)
            ->patchJson('/api/v1/admissions/APP-2026-0001', [
                'admission_status' => 'admitted',
            ])->assertOk()
            ->assertJsonPath('data.admission_status', 'admitted');

        // 10. Batch ingestion endpoint
        $batchResp = $this->withToken($this->oasToken)
            ->postJson('/api/v1/admissions', [
                'batch' => [
                    [
                        'applicant_number' => 'APP-2026-0002',
                        'first_name' => 'Andres',
                        'last_name' => 'Bonifacio',
                        'email' => 'andres@plp.edu.ph',
                        'program_applied' => 'BSA',
                    ],
                    [
                        'applicant_number' => 'APP-2026-0003',
                        'first_name' => 'Apolinario',
                        'last_name' => 'Mabini',
                        'email' => 'apolinario@plp.edu.ph',
                        'program_applied' => 'BSBA',
                    ],
                ],
            ]);

        $batchResp->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('processed_count', 2);

        $this->assertDatabaseHas('student_applicants', ['applicant_number' => 'APP-2026-0002']);
        $this->assertDatabaseHas('student_applicants', ['applicant_number' => 'APP-2026-0003']);
    }

    public function test_enrollment_endpoint_transitions_applicant_and_allocates_units(): void
    {
        // Setup admitted applicant
        $applicant = StudentApplicant::create([
            'applicant_number' => 'APP-2026-9001',
            'first_name' => 'Marcelo',
            'last_name' => 'Del Pilar',
            'email' => 'marcelo@plp.edu.ph',
            'program_applied' => 'BS Information Technology',
            'admission_status' => 'pending',
            'school_id' => 'PLP',
            'campus' => 'Main',
        ]);

        $corDocId = $this->createDocument($this->esToken, 'Certificate of Registration 2026', 'ES');

        // Transition applicant to enrollee status via ES connector
        $response = $this->withToken($this->esToken)
            ->postJson('/api/v1/enrollment', [
                'student_number' => '2026-00101-MN-0',
                'applicant_id' => $applicant->id,
                'program' => 'BS Information Technology',
                'year_level' => 1,
                'academic_year' => '2026-2027',
                'semester' => '1st Semester',
                'enrolled_units' => 21,
                'course_allocations' => [
                    ['course_code' => 'IT101', 'description' => 'Intro to IT', 'units' => 3, 'section' => 'IT1A'],
                    ['course_code' => 'IT102', 'description' => 'Programming 1', 'units' => 3, 'section' => 'IT1A'],
                ],
                'enrollment_status' => 'enrolled',
                'clearance_status' => 'cleared',
                'cor_document_id' => $corDocId,
            ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.student_number', '2026-00101-MN-0')
            ->assertJsonPath('data.enrolled_units', 21);

        // Governance: Verify that applicant's status transitioned to admitted
        $this->assertSame('admitted', $applicant->fresh()->admission_status);

        // Verify relational link to COR document
        $enrollment = StudentEnrollment::where('student_number', '2026-00101-MN-0')->first();
        $this->assertNotNull($enrollment);
        $this->assertSame($corDocId, $enrollment->cor_document_id);
        $this->assertEquals(21, $enrollment->enrolled_units);

        // GET list and show
        $this->withToken($this->esToken)
            ->getJson('/api/v1/enrollment?program=Information')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($this->esToken)
            ->getJson('/api/v1/enrollment/2026-00101-MN-0')
            ->assertOk()
            ->assertJsonPath('data.student_number', '2026-00101-MN-0');

        // PATCH update units
        $this->withToken($this->esToken)
            ->patchJson('/api/v1/enrollment/2026-00101-MN-0', [
                'enrolled_units' => 24,
            ])->assertOk()
            ->assertJsonPath('data.enrolled_units', 24);

        // Batch enrollment
        $this->withToken($this->esToken)
            ->postJson('/api/v1/enrollment', [
                'batch' => [
                    [
                        'student_number' => '2026-00102-MN-0',
                        'program' => 'BSCS',
                        'academic_year' => '2026-2027',
                        'semester' => '1st Semester',
                        'enrolled_units' => 18,
                    ],
                ],
            ])->assertCreated()
            ->assertJsonPath('processed_count', 1);
    }

    public function test_cashier_endpoint_tracks_assessments_and_verifies_payments(): void
    {
        $receiptDocId = $this->createDocument($this->cashierToken, 'Official Receipt 2026-001', 'CASHIER');

        // Create assessment
        $response = $this->withToken($this->cashierToken)
            ->postJson('/api/v1/cashier', [
                'assessment_number' => 'ASSESS-2026-001',
                'student_number' => '2026-00101-MN-0',
                'student_name' => 'Marcelo Del Pilar',
                'account_type' => 'tuition',
                'academic_year' => '2026-2027',
                'semester' => '1st Semester',
                'total_amount' => 15000.00,
                'amount_paid' => 5000.00,
                'payment_method' => 'cash',
            ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.balance', '10000.00')
            ->assertJsonPath('data.payment_status', 'assessed');

        // Verify payment with Official Receipt number and receipt document
        $verifyResp = $this->withToken($this->cashierToken)
            ->postJson('/api/v1/cashier/ASSESS-2026-001/verify', [
                'or_number' => 'OR-2026-99123',
                'amount_paid' => 10000.00,
                'payment_method' => 'online_transfer',
                'receipt_document_id' => $receiptDocId,
                'verified_by' => 'Teller Jane Doe',
            ]);

        $verifyResp->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.payment_status', 'verified')
            ->assertJsonPath('data.or_number', 'OR-2026-99123')
            ->assertJsonPath('data.amount_paid', '15000.00')
            ->assertJsonPath('data.balance', '0.00');

        $this->assertDatabaseHas('cashier_assessments', [
            'assessment_number' => 'ASSESS-2026-001',
            'or_number' => 'OR-2026-99123',
            'payment_status' => 'verified',
            'receipt_document_id' => $receiptDocId,
        ]);

        // GET cashier list and show
        $this->withToken($this->cashierToken)
            ->getJson('/api/v1/cashier?status=verified')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($this->cashierToken)
            ->getJson('/api/v1/cashier/ASSESS-2026-001')
            ->assertOk()
            ->assertJsonPath('data.or_number', 'OR-2026-99123');
    }

    public function test_payroll_endpoint_manages_records_and_centralized_payslips(): void
    {
        $payslipDocId = $this->createDocument($this->pmsToken, 'Faculty Payslip October 2026', 'PMS');

        // Ingest payroll record with gross, deductions, breakdown, and payslip document link
        $response = $this->withToken($this->pmsToken)
            ->postJson('/api/v1/payroll', [
                'payroll_period' => '2026-10-A',
                'employee_id' => 'EMP-10023',
                'employee_name' => 'Prof. Graciano Lopez Jaena',
                'department' => 'College of Arts and Sciences',
                'employment_type' => 'faculty',
                'gross_pay' => 50000.00,
                'deductions' => 6000.00,
                'deduction_breakdown' => [
                    'tax' => 3000,
                    'gsis' => 2000,
                    'philhealth' => 1000,
                ],
                'disbursement_status' => 'processed',
                'payslip_document_id' => $payslipDocId,
            ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.net_pay', '44000.00')
            ->assertJsonPath('data.employee_id', 'EMP-10023');

        $this->assertDatabaseHas('payroll_records', [
            'payroll_period' => '2026-10-A',
            'employee_id' => 'EMP-10023',
            'payslip_document_id' => $payslipDocId,
        ]);

        // GET list and show
        $this->withToken($this->pmsToken)
            ->getJson('/api/v1/payroll?period=2026-10-A&department=Arts')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->withToken($this->pmsToken)
            ->getJson('/api/v1/payroll/EMP-10023?period=2026-10-A')
            ->assertOk()
            ->assertJsonPath('data.employee_name', 'Prof. Graciano Lopez Jaena');

        // PATCH mark disbursed
        $this->withToken($this->pmsToken)
            ->patchJson('/api/v1/payroll/EMP-10023', [
                'disbursement_status' => 'disbursed',
            ])->assertOk()
            ->assertJsonPath('data.disbursement_status', 'disbursed');

        // Batch payroll ingestion
        $this->withToken($this->pmsToken)
            ->postJson('/api/v1/payroll', [
                'batch' => [
                    [
                        'payroll_period' => '2026-10-A',
                        'employee_id' => 'EMP-10024',
                        'employee_name' => 'Staff Juan Sumulong',
                        'department' => 'Accounting',
                        'gross_pay' => 30000.00,
                        'deductions' => 3000.00,
                    ],
                ],
            ])->assertCreated()
            ->assertJsonPath('processed_count', 1);
    }

    public function test_universal_documents_tagging_status_tracking_and_retrieval(): void
    {
        $file = UploadedFile::fake()->create('contract.pdf', 200, 'application/pdf');

        // Ingestion with metadata tagging
        $uploadResp = $this->withToken($this->emsToken)
            ->withHeader('Idempotency-Key', 'doc-contract-01')
            ->postJson('/api/v1/documents', [
                'title' => 'Faculty Employment Contract',
                'classification' => 'Internal',
                'file' => $file,
                'category' => 'Employment',
                'reference' => 'HR-2026-CONT-01',
                'tags' => ['faculty', 'contract', '2026'],
                'metadata' => [
                    'faculty_rank' => 'Associate Professor',
                    'tenure' => 'Permanent',
                ],
            ]);

        $uploadResp->assertCreated();
        $docId = $uploadResp->json('document_id');

        // Retrieve document details and verify tags/metadata are decoded
        $docResp = $this->withToken($this->emsToken)
            ->getJson('/api/v1/documents/'.$docId)
            ->assertOk()
            ->assertJsonPath('data.title', 'Faculty Employment Contract')
            ->assertJsonPath('data.reference', 'HR-2026-CONT-01');

        $this->assertEquals(['faculty', 'contract', '2026'], (array) $docResp->json('data.tags'));
        $this->assertEquals('Associate Professor', $docResp->json('data.metadata.faculty_rank'));

        // Update document metadata via PATCH /api/v1/documents/{document}
        $patchResp = $this->withToken($this->emsToken)
            ->patchJson('/api/v1/documents/'.$docId, [
                'reference' => 'HR-2026-CONT-REVISED',
                'tags' => ['faculty', 'contract', '2026', 'signed'],
            ]);

        $patchResp->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.reference', 'HR-2026-CONT-REVISED');

        $this->assertContains('signed', (array) $patchResp->json('data.tags'));
    }

    public function test_public_health_and_ping_endpoints(): void
    {
        // 1. GET /api/v1/health returns HTTP 200 with online subsystems
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('service', 'PLP Enterprise DMS')
            ->assertJsonStructure([
                'status',
                'service',
                'version',
                'timestamp',
                'subsystems' => [
                    'admissions',
                    'enrollment',
                    'cashier',
                    'payroll',
                    'documents',
                ],
            ]);

        // 2. GET /api/v1/ping returns pong status
        $this->getJson('/api/v1/ping')
            ->assertOk()
            ->assertJsonPath('status', 'pong')
            ->assertJsonStructure(['status', 'timestamp']);
    }

    public function test_camelcase_payload_normalization_for_admissions_and_enrollment(): void
    {
        // Admissions with camelCase keys
        $admissionsResp = $this->withToken($this->oasToken)
            ->postJson('/api/v1/admissions', [
                'applicantNumber' => 'APP-CAMEL-001',
                'firstName' => 'Apolinario',
                'middleName' => 'Mabini',
                'lastName' => 'Marasigan',
                'email' => 'apolinario.mabini@plp.edu.ph',
                'programApplied' => 'Bachelor of Science in Information Technology',
                'admissionStatus' => 'admitted',
                'appliedAt' => '2026-03-01',
                'metadata' => ['scholarship' => 'Full Academic'],
            ]);

        $admissionsResp->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.applicant_number', 'APP-CAMEL-001')
            ->assertJsonPath('data.first_name', 'Apolinario')
            ->assertJsonPath('data.program_applied', 'Bachelor of Science in Information Technology');

        $this->assertDatabaseHas('student_applicants', [
            'applicant_number' => 'APP-CAMEL-001',
            'first_name' => 'Apolinario',
            'admission_status' => 'admitted',
        ]);

        // Enrollment with camelCase keys linking applicant
        $enrollmentResp = $this->withToken($this->esToken)
            ->postJson('/api/v1/enrollment', [
                'studentNumber' => 'SN-CAMEL-2026-001',
                'applicantId' => 'APP-CAMEL-001',
                'program' => 'Bachelor of Science in Information Technology',
                'yearLevel' => 1,
                'academicYear' => '2026-2027',
                'semester' => '1st Semester',
                'enrolledUnits' => 21,
                'enrollmentStatus' => 'enrolled',
                'clearanceStatus' => 'cleared',
                'courseAllocations' => [
                    ['courseCode' => 'CS101', 'courseTitle' => 'Intro to Programming', 'units' => 3],
                    ['courseCode' => 'CS102', 'courseTitle' => 'Discrete Math', 'units' => 3],
                ],
            ]);

        $enrollmentResp->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.student_number', 'SN-CAMEL-2026-001')
            ->assertJsonPath('data.first_name', 'Apolinario')
            ->assertJsonPath('data.enrolled_units', 21);

        $this->assertDatabaseHas('student_enrollments', [
            'student_number' => 'SN-CAMEL-2026-001',
            'enrolled_units' => 21,
            'academic_year' => '2026-2027',
        ]);

        // Update enrollment with camelCase
        $this->withToken($this->esToken)
            ->patchJson('/api/v1/enrollment/SN-CAMEL-2026-001', [
                'enrolledUnits' => 24,
                'enrollmentStatus' => 'completed',
            ])->assertOk()
            ->assertJsonPath('data.enrolled_units', 24)
            ->assertJsonPath('data.enrollment_status', 'completed');
    }

    public function test_camelcase_payload_normalization_for_cashier_and_payroll(): void
    {
        // Cashier with camelCase
        $cashierResp = $this->withToken($this->cashierToken)
            ->postJson('/api/v1/cashier', [
                'assessmentNumber' => 'ASSESS-CAMEL-001',
                'studentNumber' => 'SN-CAMEL-2026-001',
                'studentName' => 'Apolinario Mabini',
                'accountType' => 'Tuition',
                'academicYear' => '2026-2027',
                'semester' => '1st Semester',
                'totalAmount' => 18500.00,
                'amountPaid' => 10000.00,
                'paymentMethod' => 'Bank Transfer',
                'paymentStatus' => 'assessed',
            ]);

        $cashierResp->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.assessment_number', 'ASSESS-CAMEL-001')
            ->assertJsonPath('data.total_amount', '18500.00')
            ->assertJsonPath('data.balance', '8500.00');

        // Cashier verify with camelCase
        $this->withToken($this->cashierToken)
            ->postJson('/api/v1/cashier/ASSESS-CAMEL-001/verify', [
                'orNumber' => 'OR-CAMEL-8888',
                'amountPaid' => 8500.00,
                'paymentMethod' => 'GCash',
                'verifiedBy' => 'Chief Teller',
            ])->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.or_number', 'OR-CAMEL-8888')
            ->assertJsonPath('data.balance', '0.00')
            ->assertJsonPath('data.payment_status', 'verified');

        // Payroll with camelCase
        $payrollResp = $this->withToken($this->pmsToken)
            ->postJson('/api/v1/payroll', [
                'payrollPeriod' => '2026-03-B',
                'employeeId' => 'EMP-CAMEL-777',
                'employeeName' => 'Marcelo Del Pilar',
                'department' => 'College of Science',
                'employmentType' => 'faculty',
                'grossPay' => 45000.00,
                'deductions' => 3500.00,
                'disbursementStatus' => 'disbursed',
            ]);

        $payrollResp->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.payroll_period', '2026-03-B')
            ->assertJsonPath('data.employee_id', 'EMP-CAMEL-777')
            ->assertJsonPath('data.net_pay', '41500.00');

        $this->assertDatabaseHas('payroll_records', [
            'payroll_period' => '2026-03-B',
            'employee_id' => 'EMP-CAMEL-777',
            'disbursement_status' => 'disbursed',
        ]);
    }

    public function test_batch_payloads_with_camelcase_keys(): void
    {
        // Batch admissions with camelCase
        $this->withToken($this->oasToken)
            ->postJson('/api/v1/admissions', [
                'batch' => [
                    [
                        'applicantNumber' => 'APP-BATCH-CAMEL-1',
                        'firstName' => 'Juan',
                        'lastName' => 'Luna',
                        'email' => 'juan.luna@plp.edu.ph',
                        'programApplied' => 'Fine Arts',
                    ],
                    [
                        'applicantNumber' => 'APP-BATCH-CAMEL-2',
                        'firstName' => 'Antonio',
                        'lastName' => 'Luna',
                        'email' => 'antonio.luna@plp.edu.ph',
                        'programApplied' => 'Pharmacy',
                    ],
                ],
            ])->assertCreated()
            ->assertJsonPath('processed_count', 2);

        // Batch cashier with camelCase
        $this->withToken($this->cashierToken)
            ->postJson('/api/v1/cashier', [
                'batch' => [
                    [
                        'assessmentNumber' => 'ASSESS-BATCH-CAMEL-1',
                        'studentName' => 'Juan Luna',
                        'accountType' => 'Graduation Fee',
                        'totalAmount' => 2500.00,
                    ],
                ],
            ])->assertCreated()
            ->assertJsonPath('processed_count', 1);

        // Batch payroll with camelCase
        $this->withToken($this->pmsToken)
            ->postJson('/api/v1/payroll', [
                'batch' => [
                    [
                        'payrollPeriod' => '2026-03-B',
                        'employeeId' => 'EMP-BATCH-CAMEL-1',
                        'employeeName' => 'Juan Luna',
                        'department' => 'College of Arts',
                        'grossPay' => 38000.00,
                    ],
                ],
            ])->assertCreated()
            ->assertJsonPath('processed_count', 1);
    }
}
