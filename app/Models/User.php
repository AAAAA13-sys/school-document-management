<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\Source;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'source', 'school_id', 'campus', 'active'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->role === Role::SuperAdmin->value) {
                // A Super Admin oversees every source; a single primary source would
                // incorrectly narrow that scope.
                $user->source = null;

                return;
            }

            $user->source = Source::normalize($user->source);
        });
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === Role::SuperAdmin->value;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin->value;
    }

    /**
     * Includes legacy staff-compatible roles so existing accounts keep working
     * until they are explicitly migrated in a later pass.
     */
    public function isStaff(): bool
    {
        return in_array($this->role, Role::staffAliases(), true);
    }

    public function isLegacyAdmin(): bool
    {
        return $this->isAdmin() && $this->sourceCode() === null;
    }

    public function sourceCode(): ?string
    {
        if ($this->isSuperAdmin()) {
            return null;
        }

        return Source::normalize($this->source) ?? Source::fromLegacyRole($this->role);
    }

    /**
     * ERP workspace codes this account can open.
     *
     * @return array<int, string>
     */
    public function sources(): array
    {
        if ($this->isSuperAdmin()) {
            return Source::values();
        }

        if ($this->isAdmin()) {
            $source = $this->sourceCode();

            // Legacy admins had no source and retain school-wide access.
            return $source ? [$source] : Source::values();
        }

        // Legacy registrar accounts covered both enrollment and admissions.
        if ($this->role === 'registrar') {
            return [Source::ES->value, Source::OAS->value];
        }

        $source = $this->sourceCode();

        return $source ? [$source] : [];
    }

    public function primarySource(): ?string
    {
        return $this->sources()[0] ?? null;
    }

    public function canAccessSource(string $source): bool
    {
        $source = Source::normalize($source);

        return $source !== null && in_array($source, $this->sources(), true);
    }

    public function homePath(): string
    {
        if ($this->isSuperAdmin() || $this->isAdmin()) {
            return '/admin';
        }

        $source = $this->primarySource();

        return $source ? '/workspaces/'.strtolower($source) : '/account';
    }
}
