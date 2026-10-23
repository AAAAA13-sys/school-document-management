<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApplicantPacket extends Model
{
    use HasFactory;

    protected $table = 'applicant_packets';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'school_id',
        'campus',
        'applicant_id',
        'revision',
        'released',
        'versions',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'released' => 'boolean',
            'versions' => 'array',
        ];
    }

    public function isReleased(): bool
    {
        return $this->released;
    }
}
