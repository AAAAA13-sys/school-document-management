<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AccountChangeService
{
    public function submit(User $actor, string $account, array $data): string
    {
        return DB::transaction(function () use ($actor, $account, $data) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $target = $this->target($actor, $account)->lockForUpdate()->first() ?? abort(404);
            $this->validate($actor, $target, $data);
            if ($actor->isSuperAdmin()) {
                $this->apply($actor, $target, $data);

                return 'applied';
            }
            $id = (string) Str::uuid();
            DB::table('account_changes')->insert(['id' => $id, 'requester_id' => $actor->id, 'account_id' => $target->id,
                'snapshot' => $this->snapshot($target), 'payload' => json_encode($data), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            Access::audit($actor, 'account_change.requested', details: ['request_id' => $id, 'account_id' => $target->id]);

            return 'pending';
        });
    }

    public function decide(User $actor, string $id, bool $approve, string $reason): void
    {
        abort_unless(trim($reason) !== '' && mb_strlen($reason) <= 2000, 422);
        DB::transaction(function () use ($actor, $id, $approve, $reason) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $row = DB::table('account_changes')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $target = $this->target($actor, (string) $row->account_id)->lockForUpdate()->first() ?? abort(404);
            $requester = User::whereKey($row->requester_id)->lockForUpdate()->first() ?? abort(404);
            abort_unless($actor->active && $actor->isSuperAdmin() && $actor->id !== $row->requester_id, 403);
            abort_unless($row->status === 'pending', 409, 'This request has already been decided.');
            abort_unless($requester->active && $requester->isAdmin() && $requester->school_id === $target->school_id && $requester->campus === $target->campus, 409, 'Requester authority has changed.');
            if ($approve) {
                abort_unless(hash_equals($row->snapshot, $this->snapshot($target)), 409, 'Account changed. Submit a new request.');
                $data = json_decode($row->payload, true);
                $this->validate($requester, $target, $data);
                $this->apply($actor, $target, $data);
            }
            DB::table('account_changes')->where('id', $id)->update(['status' => $approve ? 'approved' : 'rejected', 'reviewer_id' => $actor->id, 'reason' => trim($reason), 'updated_at' => now()]);
            Access::audit($actor, $approve ? 'account_change.approved' : 'account_change.rejected', details: ['request_id' => $id, 'account_id' => $target->id, 'reason' => trim($reason)]);
        });
    }

    private function validate(User $actor, User $target, array $data): void
    {
        abort_unless($actor->active, 403);
        Gate::forUser($actor)->authorize('update', $target);
        Gate::forUser($actor)->authorize('changeRole', [$target, $data['role']]);
        Gate::forUser($actor)->authorize('changeSource', [$target, $data['source']]);
        abort_if(isset($data['password']), 422, 'Recipients set their own passwords.');
        abort_if($actor->id === $target->id && ($data['role'] !== $target->role || $data['source'] !== $target->source || ! $data['active']), 422, 'You cannot demote, move or deactivate yourself.');
    }

    private function apply(User $actor, User $target, array $data): void
    {
        $scopeChanged = $target->source !== $data['source'] || $target->role !== $data['role'];
        $invalidate = $scopeChanged || ! $data['active'] || $target->email !== $data['email'];
        $target->fill(Arr::only($data, ['name', 'email', 'role', 'source', 'active']));
        if ($invalidate) {
            $target->auth_version++;
            $target->setRememberToken(Str::random(60));
        }
        $target->save();
        if ($scopeChanged) {
            DB::table('workspace_memberships')->where('user_id', $target->id)->delete();
        }
        if ($target->source) {
            WorkspaceRegistry::assign($target, $target->source);
        }
        if ($invalidate) {
            DB::table('sessions')->where('user_id', $target->id)->delete();
        }
        Access::audit($actor, 'account.updated', details: ['account_id' => $target->id]);
    }

    private function snapshot(User $user): string
    {
        return hash('sha256', json_encode($user->only(['name', 'email', 'role', 'source', 'active', 'auth_version'])));
    }

    private function target(User $actor, string $id)
    {
        return User::whereKey($id)->where('school_id', $actor->school_id)->where('campus', $actor->campus)
            ->when($actor->isAdmin(), fn ($q) => $q->whereIn('source', $actor->sources()));
    }
}
