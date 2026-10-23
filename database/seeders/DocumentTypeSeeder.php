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

        // Seed OAS applicant document types
        $oasDocuments = [
            [
                'code' => 'PSA',
                'name' => 'PSA Birth Certificate',
                'workspace' => 'OAS',
                'classification' => 'Confidential',
                'description' => 'Official PSA-issued birth certificate for applicant verification.',
            ],
            [
                'code' => 'FORM_137',
                'name' => 'Form 137 / Academic Record',
                'workspace' => 'OAS',
                'classification' => 'Confidential',
                'description' => 'Secondary student permanent record or Grade 11/12 report card.',
            ],
            [
                'code' => 'VALID_ID',
                'name' => 'Valid ID / Identification',
                'workspace' => 'OAS',
                'classification' => 'Confidential',
                'description' => 'Government-issued ID or school ID of applicant or parent/guardian.',
            ],
        ];

        // Seed Internal Office document types
        $internalDocuments = [
            [
                'code' => 'MINUTES',
                'name' => 'Meeting Minutes',
                'workspace' => 'INTERNAL',
                'classification' => 'Internal',
                'description' => 'Official minutes of internal office and departmental meetings.',
            ],
            [
                'code' => 'POLICY_DRAFT',
                'name' => 'Policy Draft',
                'workspace' => 'INTERNAL',
                'classification' => 'Internal',
                'description' => 'Draft administrative, academic, or office policy documents.',
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
