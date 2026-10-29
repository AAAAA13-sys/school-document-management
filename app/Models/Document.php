<?php

namespace App\Models;

use App\Services\Access;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Document extends Model
{
    use HasFactory;

    protected $table = 'documents';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'owner_id',
        'folder_id',
        'source',
        'school_id',
        'campus',
        'title',
        'subject',
        'reference',
        'category',
        'classification',
        'revision',
        'trashed_at',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'trashed_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'folder_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id')->orderBy('number');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(DocumentVersion::class, 'document_id')->latestOfMany('number');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(Share::class, 'document_id');
    }

    public function activeShares(): HasMany
    {
        return $this->hasMany(Share::class, 'document_id')
            ->whereNull('revoked_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'document_id')->orderByDesc('id');
    }

    public function stars(): HasMany
    {
        return $this->hasMany(Star::class, 'document_id');
    }

    public function auditEntries(): HasMany
    {
        return $this->hasMany(AuditEntry::class, 'document_id')->orderByDesc('id');
    }

    public function sensitiveChanges(): HasMany
    {
        return $this->hasMany(SensitiveChange::class, 'document_id')->orderByDesc('created_at');
    }

    public function memoPublication(): HasOne
    {
        return $this->hasOne(MemoPublication::class, 'document_id');
    }

    public function scopeUntrashed(Builder $query): Builder
    {
        return $query->whereNull('trashed_at');
    }

    public function scopeTrashed(Builder $query): Builder
    {
        return $query->whereNotNull('trashed_at');
    }

    public function scopeInSource(Builder $query, string $source): Builder
    {
        return $query->where('source', strtoupper($source));
    }

    public function scopeVisibleTo(Builder $query, object $actor): Builder
    {
        Access::visibleTo($query->getQuery(), $actor);

        return $query;
    }

    public function isTrashed(): bool
    {
        return $this->trashed_at !== null;
    }

    public function isInternal(): bool
    {
        return $this->classification === 'Internal';
    }

    public function isConfidential(): bool
    {
        return $this->classification === 'Confidential';
    }

    public function isRestricted(): bool
    {
        return $this->classification === 'Restricted';
    }
}
