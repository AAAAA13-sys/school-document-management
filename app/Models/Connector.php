<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Connector extends Model
{
    use HasFactory;

    protected $table = 'connectors';

    protected $fillable = [
        'name',
        'source',
        'school_id',
        'campus',
        'token_hash',
        'active',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function auditEntries(): HasMany
    {
        return $this->hasMany(AuditEntry::class, 'connector_id');
    }
}
