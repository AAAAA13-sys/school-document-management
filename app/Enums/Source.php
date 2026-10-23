<?php

namespace App\Enums;

enum Source: string
{
    case EMS = 'EMS';
    case PMS = 'PMS';
    case ES = 'ES';
    case OAS = 'OAS';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::EMS->value => 'Employee Management',
            self::PMS->value => 'Payroll Management',
            self::ES->value => 'Enrollment',
            self::OAS->value => 'Online Admission',
        ];
    }

    public static function normalize(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value));

        return in_array($value, self::values(), true) ? $value : null;
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
