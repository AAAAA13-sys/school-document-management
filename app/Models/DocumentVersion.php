<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentVersion extends Model
{
    use HasFactory;

    protected $table = 'document_versions';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (DocumentVersion $version): void {
            if (! $version->created_at) {
                $version->created_at = now();
            }
        });
    }

    protected $fillable = [
        'id',
        'document_id',
        'number',
        'filename',
        'mime',
        'size',
        'checksum',
        'storage_key',
        'scan_status',
        'scan_attempts',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'size' => 'integer',
            'scan_attempts' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function isScannedClean(): bool
    {
        return $this->scan_status === 'Clean';
    }

    public function isPending(): bool
    {
        return $this->scan_status === 'Pending';
    }

    public function isQuarantined(): bool
    {
        return $this->scan_status === 'Quarantined';
    }

    public function isFailed(): bool
    {
        return $this->scan_status === 'Failed';
    }
}
