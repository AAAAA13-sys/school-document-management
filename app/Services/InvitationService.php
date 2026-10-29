<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class InvitationService
{
    public function request(User $actor, array $data): string
    {
        abort_unless($actor->active && ($actor->isAdmin() || $actor->isSuperAdmin()), 403);
        Gate::forUser($actor)->authorize('accounts.role', $data['role']);
        Gate::forUser($actor)->authorize('accounts.source', $data['source'] ?? null);
        abort_if($data['role'] !== 'super_admin' && empty($data['source']), 422, 'A workspace is required.');
        $data['email'] = strtolower(trim($data['email']));
        abort_if(User::whereRaw('LOWER(email) = ?', [$data['email']])->exists(), 422, 'An account already exists.');
        abort_if(DB::table('account_invitations')->where('email', $data['email'])->whereIn('status', ['pending', 'approved', 'sent'])->exists(), 409, 'An active invitation already exists for this email.');
        $id = (string) Str::uuid();
        try {
            DB::transaction(function () use ($actor, $data, $id) {
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                abort_unless($actor->active, 403);
                Gate::forUser($actor)->authorize('accounts.role', $data['role']);
                Gate::forUser($actor)->authorize('accounts.source', $data['source'] ?? null);
                DB::table('account_invitations')->insert([
                    'id' => $id, 'requested_by' => $actor->id, 'name' => $data['name'],
                    'email' => strtolower($data['email']), 'active_email' => $data['email'], 'role' => $data['role'],
                    'source' => $data['role'] === 'super_admin' ? null : $data['source'],
                    'school_id' => $actor->school_id, 'campus' => $actor->campus,
                    'status' => $actor->isSuperAdmin() ? 'approved' : 'pending',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                Access::audit($actor, 'invitation.requested', details: ['invitation_id' => $id]);
            });

        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '23505']) && DB::table('account_invitations')->where('active_email', $data['email'])->exists()) {
                abort(409, 'An active invitation already exists for this email.');
            }
            throw $e;
        }

        return $id;
    }

    public function review(User $actor, string $id, bool $approve, string $reason): void
    {
        abort_unless(trim($reason) !== '' && mb_strlen($reason) <= 2000, 422);
        DB::transaction(function () use ($actor, $id, $approve, $reason) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $row = $this->scoped($actor, $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless($actor->active && $actor->isSuperAdmin() && $row->requested_by !== $actor->id, 403);
            abort_unless($row->status === 'pending', 409, 'This invitation has already been reviewed.');
            $requester = User::whereKey($row->requested_by)->lockForUpdate()->first();
            abort_unless($requester?->active && $requester->isAdmin() && in_array($row->source, $requester->sources(), true), 409, 'Requester authority has changed.');
            DB::table('account_invitations')->where('id', $id)->update(['status' => $approve ? 'approved' : 'rejected', 'active_email' => $approve ? $row->email : null, 'reason' => $reason, 'reviewed_by' => $actor->id, 'updated_at' => now()]);
            Access::audit($actor, $approve ? 'invitation.approved' : 'invitation.rejected', details: ['invitation_id' => $id, 'reason' => $reason]);
        });
    }

    public function deliver(User $actor, string $id): void
    {
        $this->requireMail();
        DB::transaction(function () use ($actor, $id) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $row = $this->scoped($actor, $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless($actor->active && $actor->isSuperAdmin(), 403);
            abort_unless(in_array($row->status, ['approved', 'sent']), 409);
            abort_if($row->sent_at && Carbon::parse($row->sent_at)->addSeconds((int) config('invitations.resend_after_seconds', 60))->isFuture(), 429, 'Please wait before resending this invitation.');
            $this->assertAuthority($row);
            $token = Str::random(64);
            DB::table('account_invitations')->where('id', $id)->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes((int) config('invitations.expire', 1440)), 'status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
            Mail::raw('Set your DMS password: '.url('/invitations/accept/'.$token), fn ($message) => $message->to($row->email)->subject('Your DMS invitation'));
            Access::audit($actor, 'invitation.sent', details: ['invitation_id' => $id]);
        });
    }

    public function accept(string $token, string $password): User
    {
        return DB::transaction(function () use ($token, $password) {
            $row = DB::table('account_invitations')->where('token_hash', hash('sha256', $token))->lockForUpdate()->first() ?? abort(404);
            abort_unless($row->status === 'sent' && $row->expires_at > now() && ! $row->accepted_at, 410, 'Invitation expired or already used.');
            abort_if(User::where('email', $row->email)->exists(), 409, 'This email already has an account.');
            $this->assertAuthority($row);
            $user = User::create(['name' => $row->name, 'email' => $row->email, 'role' => $row->role, 'source' => $row->source, 'school_id' => $row->school_id, 'campus' => $row->campus, 'active' => true, 'password' => $password]);
            if ($user->source) {
                WorkspaceRegistry::assign($user, $user->source);
            }
            DB::table('account_invitations')->where('id', $row->id)->update(['status' => 'accepted', 'active_email' => null, 'accepted_at' => now(), 'token_hash' => null, 'updated_at' => now()]);
            Access::audit($user, 'invitation.accepted', details: ['invitation_id' => $row->id]);

            return $user;
        });
    }

    public function revoke(User $actor, string $id): void
    {
        DB::transaction(function () use ($actor, $id) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $row = $this->scoped($actor, $id)->lockForUpdate()->first() ?? abort(404);
            abort_unless($actor->active && ($actor->isSuperAdmin() || ($actor->isAdmin() && $row->requested_by === $actor->id)), 403);
            abort_unless(in_array($row->status, ['pending', 'approved', 'sent']), 409);
            DB::table('account_invitations')->where('id', $id)->update(['status' => 'revoked', 'active_email' => null, 'token_hash' => null, 'updated_at' => now()]);
            Access::audit($actor, 'invitation.revoked', details: ['invitation_id' => $id]);
        });
    }

    private function assertAuthority(object $row): void
    {
        $requester = User::whereKey($row->requested_by)->lockForUpdate()->first();
        abort_unless($requester?->active && $requester->school_id === $row->school_id && $requester->campus === $row->campus, 409, 'Invitation authority has changed.');
        if ($requester->isSuperAdmin()) {
            return;
        }
        $reviewer = User::whereKey($row->reviewed_by)->lockForUpdate()->first();
        abort_unless($requester->isAdmin() && $row->role === 'staff' && in_array($row->source, $requester->sources(), true)
            && $reviewer?->active && $reviewer->isSuperAdmin() && $reviewer->school_id === $row->school_id && $reviewer->campus === $row->campus,
            409, 'Invitation authority has changed.');
    }

    public function scoped(User $actor, string $id)
    {
        return DB::table('account_invitations')->where('id', $id)->where('school_id', $actor->school_id)->where('campus', $actor->campus);
    }

    public function requireMail(): void
    {
        $transport = config('mail.mailers.'.config('mail.default').'.transport');
        abort_unless(in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun']), 503, 'Configure an outbound email transport before sending account links.');
    }
}
