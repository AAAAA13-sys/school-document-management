<?php

namespace App\Models;

use App\Enums\Role;
use App\Enums\Source;
use App\Services\WorkspaceRegistry;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        return WorkspaceRegistry::codes($this);
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

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'owner_id');
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class, 'owner_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class, 'recipient_id');
    }

    public function folderShares(): HasMany
    {
        return $this->hasMany(FolderShare::class, 'recipient_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'user_id');
    }

    public function stars(): HasMany
    {
        return $this->hasMany(Star::class, 'user_id');
    }

    public function auditEntries(): HasMany
    {
        return $this->hasMany(AuditEntry::class, 'actor_id');
    }

    public function workspaceMemberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class, 'user_id');
    }

    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_memberships');
    }

    public function sensitiveChangesRequested(): HasMany
    {
        return $this->hasMany(SensitiveChange::class, 'requester_id');
    }

    public function sensitiveChangesReviewed(): HasMany
    {
        return $this->hasMany(SensitiveChange::class, 'reviewer_id');
    }

    public function accountInvitationsRequested(): HasMany
    {
        return $this->hasMany(AccountInvitation::class, 'requested_by');
    }

    public function accountInvitationsReviewed(): HasMany
    {
        return $this->hasMany(AccountInvitation::class, 'reviewed_by');
    }

    public function accountChangesRequested(): HasMany
    {
        return $this->hasMany(AccountChange::class, 'requester_id');
    }

    public function accountChangesReviewed(): HasMany
    {
        return $this->hasMany(AccountChange::class, 'reviewer_id');
    }

    public function legalHolds(): HasMany
    {
        return $this->hasMany(AuditLegalHold::class, 'actor_id');
    }
}
