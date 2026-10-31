<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        // Strictly remove any PMS, EMS, or ES transactional types
        DB::table('document_types')
            ->whereIn('workspace', ['PMS', 'EMS', 'ES'])
            ->orWhereIn('code', DocumentType::PROHIBITED_TYPES)
            ->delete();

        // Seed OAS applicant document types (SDD v2.0 Section 3.6)
        $oasDocuments = [
            [
                'code' => 'PSA',
                'name' => 'PSA Birth Certificate',
                'workspace' => 'OAS',
                'classification' => 'Confidential',
                'description' => 'Official PSA-issued birth certificate for applicant identity and birth verification.',
            ],
            [
                'code' => 'FORM_137',
                'name' => 'Form 137 / Academic Record',
                'workspace' => 'OAS',
                'classification' => 'Confidential',
                'description' => 'Secondary student permanent record or Form 138 report card for academic verification.',
            ],
            [
                'code' => 'VALID_ID',
                'name' => 'Applicant Identification Document',
                'workspace' => 'OAS',
                'classification' => 'Confidential',
                'description' => 'Government-issued ID or official student ID for applicant identity verification.',
            ],
            [
                'code' => 'PARENT_ID',
                'name' => 'Parent/Guardian Identification Document',
                'workspace' => 'OAS',
                'classification' => 'Confidential',
                'description' => 'Government or official ID verifying parent or guardian identity.',
            ],
            [
                'code' => 'AFFIDAVIT',
                'name' => 'Guardianship Affidavit',
                'workspace' => 'OAS',
                'classification' => 'Confidential',
                'description' => 'Legal notarized affidavit establishing legal guardianship where applicable.',
            ],
            [
                'code' => 'PHOTO',
                'name' => 'Applicant Photograph',
                'workspace' => 'OAS',
                'classification' => 'Confidential',
                'description' => 'Official passport or 2x2 photograph associated with the admission application.',
            ],
            [
                'code' => 'EXAM_TICKET',
                'name' => 'Signed Examination Ticket',
                'workspace' => 'OAS',
                'classification' => 'Confidential',
                'description' => 'Signed examination ticket verifying admission examination processing.',
            ],
        ];

        // Seed Internal Office document types (SDD v2.0 Section 3.6.2)
        $internalDocuments = [
            [
                'code' => 'MEMO',
                'name' => 'Institutional Memorandum',
                'workspace' => 'INTERNAL',
                'classification' => 'Internal',
                'description' => 'Official institutional policy memorandum for internal school circulation.',
            ],
            [
                'code' => 'MINUTES',
                'name' => 'Meeting Minutes / Agendas',
                'workspace' => 'INTERNAL',
                'classification' => 'Internal',
                'description' => 'Official minutes and agendas of internal office and departmental meetings.',
            ],
            [
                'code' => 'POLICY_DRAFT',
                'name' => 'Policy Drafts and Approvals',
                'workspace' => 'INTERNAL',
                'classification' => 'Internal',
                'description' => 'Draft administrative, academic, or office policy documents and revisions.',
            ],
            [
                'code' => 'CORRESPONDENCE',
                'name' => 'General Office Correspondence',
                'workspace' => 'INTERNAL',
                'classification' => 'Internal',
                'description' => 'Official letters, requests, and external communications for institutional records.',
            ],
        ];

        foreach (array_merge($oasDocuments, $internalDocuments) as $type) {
            DocumentType::updateOrCreate(
                ['code' => $type['code']],
                $type + ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
