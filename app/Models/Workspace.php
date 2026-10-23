<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workspace extends Model
{
    use HasFactory;

    protected $table = 'workspaces';

    protected $fillable = [
        'school_id',
        'campus',
        'code',
        'name',
        'kind',
        'status',
    ];

    public function memberships(): HasMany
    {
        return $this->hasMany(WorkspaceMembership::class, 'workspace_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_memberships');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
