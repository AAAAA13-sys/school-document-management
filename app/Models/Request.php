<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Request extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'type',
        'priority',
        'status',
        'reviewer_id',
        'reason',
    ];

    protected $casts = [
        'status' => 'string',
        'type' => 'string',
        'priority' => 'string',
        'reviewed_by' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
