<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLegalHold extends Model
{
    use HasFactory;

    protected $table = 'audit_legal_holds';

    protected $fillable = [
        'school_id',
        'campus',
        'reason',
        'actor_id',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    public function scopeActive($query)
    {
        return $query->whereNull('released_at');
    }
}
