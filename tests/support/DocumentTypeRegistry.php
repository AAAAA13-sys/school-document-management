<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * QA Contract Stub for DocumentTypeRegistry (SDD v1.1 Scope Enforcement).
 *
 * Ground Truth (docs/DMS-SDD-V1.1.pdf Section 1.1):
 * Scope reduction: Removed PMS, EMS, and ES document custody.
 * DMS is strictly the OAS-to-ES bridge and Internal workspace.
 *
 * // TODO(BackendAgent): Move this class to app/Services/DocumentTypeRegistry.php
 * // and bind to container if necessary.
 */
if (! class_exists(DocumentTypeRegistry::class, false)) {
    class DocumentTypeRegistry
    {
        /**
         * Allowed sources that hold document types in DMS.
         * PMS, EMS, and ES transactional custody are strictly excluded.
         */
        public const ALLOWED_SOURCES = ['OAS', 'INTERNAL'];

        /**
         * Approved document types per source.
         *
         * @var array<string, array<string, string>>
         */
        protected static array $types = [
            'OAS' => [
                'PSA_BIRTH_CERTIFICATE' => 'PSA Birth Certificate',
                'FORM_137' => 'Form 137 / Transcript of Records',
                'VALID_ID' => 'Government / Student Valid ID',
                'REPORT_CARD' => 'High School Report Card',
            ],
            'INTERNAL' => [
                'MINUTES' => 'Meeting Minutes',
                'POLICY_DRAFT' => 'Policy Draft',
                'OFFICE_ORDER' => 'Office Order',
                'RESOLUTION' => 'Board Resolution',
            ],
        ];

        /**
         * Return all registered types grouped by source or flat.
         *
         * @return array<string, array<string, string>>
         */
        public static function all(): array
        {
            return static::$types;
        }

        /**
         * Return types for a specific source.
         *
         * @return array<string, string>
         */
        public static function forSource(string $source): array
        {
            $source = strtoupper($source);

            return static::$types[$source] ?? [];
        }

        /**
         * Check if a source is permitted in the document type registry.
         */
        public static function allowsSource(string $source): bool
        {
            return in_array(strtoupper($source), static::ALLOWED_SOURCES, true);
        }

        /**
         * Check if a type code is valid and belongs to an authorized source.
         */
        public static function hasType(string $typeCode): bool
        {
            $typeCode = strtoupper($typeCode);
            foreach (static::$types as $group) {
                if (isset($group[$typeCode])) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Attempt to register a document type.
         * Fails with InvalidArgumentException if the source is PMS, EMS, ES, or non-approved.
         *
         * @throws InvalidArgumentException
         */
        public static function register(string $source, string $code, string $label): bool
        {
            $source = strtoupper($source);
            $code = strtoupper($code);

            if (! in_array($source, static::ALLOWED_SOURCES, true)) {
                throw new InvalidArgumentException(
                    "Cannot register document type [{$code}] for source [{$source}]. SDD v1.1 restricts custody to OAS and Internal workspaces only."
                );
            }

            static::$types[$source][$code] = $label;

            return true;
        }

        /**
         * Reset registry to canonical SDD v1.1 state.
         */
        public static function reset(): void
        {
            static::$types = [
                'OAS' => [
                    'PSA_BIRTH_CERTIFICATE' => 'PSA Birth Certificate',
                    'FORM_137' => 'Form 137 / Transcript of Records',
                    'VALID_ID' => 'Government / Student Valid ID',
                    'REPORT_CARD' => 'High School Report Card',
                ],
                'INTERNAL' => [
                    'MINUTES' => 'Meeting Minutes',
                    'POLICY_DRAFT' => 'Policy Draft',
                    'OFFICE_ORDER' => 'Office Order',
                    'RESOLUTION' => 'Board Resolution',
                ],
            ];
        }
    }
}
