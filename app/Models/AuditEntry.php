<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEntry extends Model
{
    use HasFactory;

    protected $table = 'audit_entries';

    public $timestamps = false;

    protected $fillable = [
        'school_id',
        'campus',
        'source',
        'actor_id',
        'connector_id',
        'action',
        'document_id',
        'details',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function connector(): BelongsTo
    {
        return $this->belongsTo(Connector::class, 'connector_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}
