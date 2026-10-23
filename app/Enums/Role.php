<?php

namespace App\Enums;

enum Role: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Staff = 'staff';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $role): string => $role->value, self::cases());
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::SuperAdmin->value => 'Super Admin',
            self::Admin->value => 'Admin',
            self::Staff->value => 'Staff',
        ];
    }

    /**
     * Legacy roles that are treated as staff until a later migration decision.
     *
     * @return array<int, string>
     */
    public static function staffAliases(): array
    {
        return [self::Staff->value, 'employee', 'teacher', 'hr', 'payroll'];
    }

    public function label(): string
    {
        return self::labels()[$this->value];
    }
}
