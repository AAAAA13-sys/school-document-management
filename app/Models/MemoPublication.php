<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemoPublication extends Model
{
    use HasFactory;

    protected $table = 'memo_publications';
    protected $primaryKey = 'document_id';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'document_id',
        'version_id',
        'type',
        'reference',
        'issuer',
        'audience',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }
}
