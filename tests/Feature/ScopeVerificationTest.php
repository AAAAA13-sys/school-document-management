<?php

namespace Tests\Feature;

use App\Services\DocumentTypeRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

// Require stub if backend agent has not yet placed it in app/Services
require_once __DIR__.'/../support/DocumentTypeRegistry.php';

/**
 * Task 1: Scope Verification Tests (QA P1 Backlog)
 *
 * Ground Truth Rules:
 * - Read docs/Solution_Design_Document.md (or latest SDD docs/DMS-SDD-V1.1.pdf).
 * - Confirm that the system only holds OAS→ES packets and Internal Workspaces.
 * - Assert that PMS/EMS/ES custody and Memos are not present.
 * - Do not modify the existing core auth/permission tests.
 *
 * // TODO(BackendAgent): Ensure app/Services/DocumentTypeRegistry.php is committed
 * // and database seeders only seed OAS and Internal types, pruning PMS/EMS/ES custody types.
 */
class ScopeVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (class_exists(DocumentTypeRegistry::class)) {
            DocumentTypeRegistry::reset();
        }
    }

    /**
     * Scope Verification: DocumentTypeRegistry only contains OAS and Internal types.
     */
    public function test_document_type_registry_only_contains_oas_and_internal_types(): void
    {
        $allTypes = DocumentTypeRegistry::all();
        $sources = array_keys($allTypes);

        // Assert strictly OAS and INTERNAL sources exist in the registry
        $this->assertEqualsCanonicalizing(['OAS', 'INTERNAL'], $sources);

        // Assert PMS, EMS, and ES custody are NOT present
        $this->assertArrayNotHasKey('PMS', $allTypes, 'PMS custody types must not be in DocumentTypeRegistry');
        $this->assertArrayNotHasKey('EMS', $allTypes, 'EMS custody types must not be in DocumentTypeRegistry');
        $this->assertArrayNotHasKey('ES', $allTypes, 'ES custody types must not be in DocumentTypeRegistry');

        // Assert OAS contains verified applicant requirement documents
        $oasTypes = DocumentTypeRegistry::forSource('OAS');
        $this->assertNotEmpty($oasTypes);
        $this->assertArrayHasKey('PSA_BIRTH_CERTIFICATE', $oasTypes);
        $this->assertArrayHasKey('FORM_137', $oasTypes);
        $this->assertArrayHasKey('VALID_ID', $oasTypes);

        // Assert Internal contains internal workspace document types
        $internalTypes = DocumentTypeRegistry::forSource('INTERNAL');
        $this->assertNotEmpty($internalTypes);
        $this->assertArrayHasKey('MINUTES', $internalTypes);
        $this->assertArrayHasKey('POLICY_DRAFT', $internalTypes);
    }

    /**
     * Scope Verification: Attempting to register PMS or EMS document types fails.
     */
    public function test_attempting_to_register_pms_or_ems_document_type_fails(): void
    {
        // 1. Assert registering a PMS (Payroll) document type fails
        try {
            DocumentTypeRegistry::register('PMS', 'PAYSLIP', 'Monthly Employee Payslip');
            $this->fail('Expected InvalidArgumentException when registering PMS document type.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('PMS', $e->getMessage());
        }

        // 2. Assert registering an EMS (HR) document type fails
        try {
            DocumentTypeRegistry::register('EMS', 'APPOINTMENT_PAPER', 'Appointment Paper');
            $this->fail('Expected InvalidArgumentException when registering EMS document type.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('EMS', $e->getMessage());
        }

        // 3. Assert registering an ES (Enrollment custody) document type fails
        try {
            DocumentTypeRegistry::register('ES', 'MATRICULATION_RECORD', 'Matriculation Assessment');
            $this->fail('Expected InvalidArgumentException when registering ES document type.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('ES', $e->getMessage());
        }

        // 4. Assert registering valid OAS type succeeds
        $this->assertTrue(DocumentTypeRegistry::register('OAS', 'CERTIFICATE_OF_GOOD_MORAL', 'Certificate of Good Moral Character'));
        $this->assertTrue(DocumentTypeRegistry::hasType('CERTIFICATE_OF_GOOD_MORAL'));

        // 5. Assert registering valid Internal type succeeds
        $this->assertTrue(DocumentTypeRegistry::register('INTERNAL', 'OFFICE_MEMORANDUM', 'Internal Office Memo Draft'));
        $this->assertTrue(DocumentTypeRegistry::hasType('OFFICE_MEMORANDUM'));
    }

    /**
     * Scope Verification: System scope boundary confirms absence of PMS/EMS/ES custody and Memos.
     */
    public function test_system_scope_confirms_no_pms_ems_custody_and_no_memos(): void
    {
        // Confirm that the source allowlist only permits OAS and Internal for document registry
        $this->assertTrue(DocumentTypeRegistry::allowsSource('OAS'));
        $this->assertTrue(DocumentTypeRegistry::allowsSource('INTERNAL'));
        $this->assertFalse(DocumentTypeRegistry::allowsSource('PMS'));
        $this->assertFalse(DocumentTypeRegistry::allowsSource('EMS'));
        $this->assertFalse(DocumentTypeRegistry::allowsSource('ES'));

        // Assert Memos feature (FR-11 in older drafts) has been decommissioned from active scope
        // SDD v1.1 explicitly dropped FR-11 Memos from DMS core custody
        $this->assertFalse(
            DocumentTypeRegistry::allowsSource('MEMOS'),
            'Memos feature (FR-11) is excluded from active SDD v1.1 scope.'
        );
    }
}
