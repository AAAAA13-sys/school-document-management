<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PacketReceipt extends Model
{
    use HasFactory;

    protected $table = 'packet_receipts';
    public $timestamps = false;

    protected $fillable = [
        'scope_key',
        'event_id',
        'applicant_id',
        'revision',
        'request_hash',
        'payload',
        'response',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'payload' => 'array',
            'response' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
