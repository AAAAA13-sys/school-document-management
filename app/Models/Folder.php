<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Folder extends Model
{
    use HasFactory;

    protected $table = 'folders';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'owner_id',
        'parent_id',
        'name',
        'source',
        'school_id',
        'campus',
        'revision',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id')->orderBy('name');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'folder_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(FolderShare::class, 'folder_id');
    }
}
