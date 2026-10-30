<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeInvitation extends Model
{
    use HasUuids;

    // State, identity and credentials may only be assigned explicitly by the service.
    protected $guarded = ['*'];

    protected $hidden = ['token_hash', 'pending_email'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'delivery_attempted_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->lessThanOrEqualTo(now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && ! $this->hasExpired();
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'Accepted',
            $this->revoked_at !== null => 'Revoked',
            $this->hasExpired() => 'Expired',
            default => 'Pending',
        };
    }

    public function deliveryLabel(): string
    {
        return match ($this->delivery_status) {
            'sending' => 'Sending',
            'sent' => 'Submitted to SMTP',
            'failed' => 'Delivery Failed',
            default => 'Not sent',
        };
    }

    public function __debugInfo(): array
    {
        return $this->toArray();
    }
}
