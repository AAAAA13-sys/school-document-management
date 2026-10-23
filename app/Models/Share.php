<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Share extends Model
{
    use HasFactory;

    protected $table = 'shares';

    protected $fillable = [
        'document_id',
        'recipient_id',
        'version_id',
        'permission',
        'expires_at',
        'revoked_at',
        'memo_generated',
        'folder_inheritance',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'memo_generated' => 'boolean',
            'folder_inheritance' => 'boolean',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    public function isActive(): bool
    {
        return ! $this->revoked_at && (! $this->expires_at || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function hasFolderInheritance(): bool
    {
        return (bool) $this->folder_inheritance;
    }
}
