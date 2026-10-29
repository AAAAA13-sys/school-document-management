<?php

namespace App\Enums;

use App\Models\User;
use App\Services\WorkspaceRegistry;

enum Source: string
{
    case EMS = 'EMS';
    case PMS = 'PMS';
    case ES = 'ES';
    case OAS = 'OAS';

    /**
     * @return array<int, string>
     */
    public static function values(?User $actor = null, bool $erpOnly = false): array
    {
        return $actor ? WorkspaceRegistry::codes($actor, $erpOnly) : array_map(fn (self $source) => $source->value, self::cases());
    }

    public static function labels(?User $actor = null, bool $erpOnly = false): array
    {
        return $actor ? WorkspaceRegistry::labels($actor, $erpOnly) : ['EMS' => 'Employee Management', 'PMS' => 'Payroll Management', 'ES' => 'Enrollment', 'OAS' => 'Online Admission'];
    }

    public static function normalize(?string $value): ?string
    {
        $value = strtoupper(trim($value ?? ''));

        return preg_match('/^[A-Z][A-Z0-9_-]{0,63}$/D', $value) ? $value : null;
    }

    /**
     * Legacy users did not store a source column. Their old role implied one.
     */
    public static function fromLegacyRole(?string $role): ?string
    {
        return match ($role) {
            'employee', 'teacher', 'hr' => self::EMS->value,
            'payroll' => self::PMS->value,
            default => null,
        };
    }

    public function documentLabel(): string
    {
        return self::labels()[$this->value];
    }
}
