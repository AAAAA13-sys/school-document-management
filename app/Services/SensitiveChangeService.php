<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** FR-13: proposals preserve live state until a separately authorized decision. */
class SensitiveChangeService
{
    public function submit(User $actor, object $doc, string $action, array $payload): array
    {
        return DB::transaction(function () use ($actor, $doc, $action, $payload) {
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $doc = DB::table('documents')->where('id', $doc->id)->lockForUpdate()->first() ?? abort(404);
            $this->validate($actor, $doc, $action, $payload);
            if ($actor->isSuperAdmin()) {
                $this->apply($actor, $doc, $action, $payload);

                return ['status' => 'applied', 'revision' => $doc->revision];
            }
            $id = (string) Str::uuid();
            DB::table('sensitive_changes')->insert(['id' => $id, 'requester_id' => $actor->id, 'document_id' => $doc->id,
                'action' => $action, 'requester_role' => $actor->role, 'revision' => $doc->revision,
                'payload' => json_encode($payload), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            Access::audit($actor, 'approval.requested', $doc, ['approval_id' => $id, 'action' => $action]);

            return ['approval_id' => $id, 'status' => 'pending'];
        }, 3);
    }

    public function canReview(User $reviewer, User $requester, object $doc): bool
    {
        return $reviewer->active && $requester->active && $reviewer->id !== $requester->id
            && $reviewer->school_id === $doc->school_id && $reviewer->campus === $doc->campus
            && ($requester->isAdmin() ? $reviewer->isSuperAdmin()
                : ($requester->isStaff() && $reviewer->isAdmin() && in_array($doc->source, $reviewer->sources(), true)));
    }

    public function decide(User $reviewer, string $id, bool $approve, string $reason): array
    {
        abort_unless(trim($reason) !== '' && mb_strlen($reason) <= 2000, 422, 'A decision reason is required.');

        return DB::transaction(function () use ($reviewer, $id, $approve, $reason) {
            $change = DB::table('sensitive_changes')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
            $reviewer = User::whereKey($reviewer->id)->lockForUpdate()->firstOrFail();
            $requester = User::whereKey($change->requester_id)->lockForUpdate()->firstOrFail();
            $doc = DB::table('documents')->where('id', $change->document_id)->lockForUpdate()->first() ?? abort(404);
            abort_unless($reviewer->school_id === $doc->school_id && $reviewer->campus === $doc->campus, 404);
            if (! $reviewer->isSuperAdmin()) {
                abort_unless(in_array($doc->source, $reviewer->sources(), true), 404);
            }
            abort_unless($this->canReview($reviewer, $requester, $doc), 403);
            abort_unless($change->status === 'pending', 409, 'This request has already been decided.');
            abort_unless($requester->role === $change->requester_role, 409, 'Requester authority changed. Submit a new request.');
            $payload = json_decode($change->payload, true);
            if ($approve) {
                $this->validate($requester, $doc, $change->action, $payload);
                $this->apply($reviewer, $doc, $change->action, $payload);
            }
            $status = $approve ? 'approved' : 'rejected';
            DB::table('sensitive_changes')->where('id', $id)->update(['status' => $status, 'reviewer_id' => $reviewer->id,
                'reason' => trim($reason), 'decided_at' => now(), 'updated_at' => now()]);
            Access::audit($reviewer, 'approval.'.$status, $doc, ['approval_id' => $id, 'reason' => trim($reason)]);

            return ['approval_id' => $id, 'status' => $status];
        }, 3);
    }

    private function validate(User $actor, object $doc, string $action, array $p): void
    {
        abort_unless($actor->active, 403);
        Access::document($actor, $doc->id);
        abort_unless((int) ($p['revision'] ?? 0) === (int) $doc->revision, 409, 'Document changed. Submit a new request.');
        abort_unless(in_array($action, ['classification', 'trash', 'restore', 'share.grant', 'share.revoke', 'memo.publish'], true), 422);
        Gate::forUser($actor)->authorize(match ($action) {
            'classification' => 'document.edit', 'share.grant', 'share.revoke' => 'document.share', default => 'document.manage',
        }, $doc);
        if ($action === 'classification') {
            abort_unless(in_array($p['classification'] ?? '', ['Internal', 'Confidential', 'Restricted'], true), 422);
            Gate::forUser($actor)->authorize('document.classify', [$doc, $p['classification']]);
        }
        if ($action === 'restore') {
            abort_unless($doc->trashed_at, 409);
        } else {
            abort_if($doc->trashed_at, 409);
        }
        if ($action === 'share.grant') {
            abort_unless(in_array($p['permission'] ?? '', ['Viewer', 'Commenter', 'Editor'], true), 422);
            abort_unless(Access::scope(DB::table('users'), $actor)->where('id', $p['recipient_id'] ?? 0)->where('active', true)->exists(), 422);
            abort_if((int) $p['recipient_id'] === $actor->id, 422);
            try {
                $expiry = isset($p['expires_at']) ? Carbon::parse($p['expires_at']) : now()->addDays(30);
            } catch (\Throwable) {
                abort(422, 'A valid sharing expiry is required.');
            }
            abort_unless($expiry->isFuture() && $expiry->lte(now()->addDays(30)), 422, 'Access must expire within 30 days.');
            if (! empty($p['version_id'])) {
                abort_unless(DB::table('document_versions')->where('id', $p['version_id'])->where('document_id', $doc->id)->exists(), 422);
                abort_if($p['permission'] === 'Editor', 422);
            }
        }
        if ($action === 'share.revoke') {
            abort_unless(DB::table('shares')->where('id', $p['share_id'] ?? 0)->where('document_id', $doc->id)->exists(), 404);
        }
        if ($action === 'memo.publish') {
            MemoService::validatePublication($actor, $doc, $p);
        }
    }

    private function apply(User $actor, object $doc, string $action, array $p): void
    {
        $update = ['revision' => $doc->revision + 1, 'updated_at' => now()];
        if ($action === 'classification') {
            $update['classification'] = $p['classification'];
        }
        if ($action === 'trash') {
            $update['trashed_at'] = now();
        }
        if ($action === 'restore') {
            $update['trashed_at'] = null;
        }
        if ($action === 'share.grant') {
            DB::table('shares')->updateOrInsert(['document_id' => $doc->id, 'recipient_id' => $p['recipient_id']],
                ['permission' => $p['permission'], 'version_id' => $p['version_id'] ?? null, 'expires_at' => $p['expires_at'] ?? now()->addDays(30), 'revoked_at' => null, 'memo_generated' => false, 'folder_inheritance' => (bool) ($p['folder_inheritance'] ?? false), 'created_at' => now(), 'updated_at' => now()]);
        }
        if ($action === 'share.revoke') {
            DB::table('shares')->where('id', $p['share_id'])->update(['revoked_at' => now(), 'updated_at' => now()]);
        }
        if ($action === 'memo.publish') {
            DB::table('shares')->where('document_id', $doc->id)->where('memo_generated', true)->whereNotIn('recipient_id', $p['audience'])->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
            DB::table('memo_publications')->updateOrInsert(['document_id' => $doc->id],
                ['version_id' => $p['version_id'], 'type' => $p['type'], 'reference' => $p['reference'], 'issuer' => $p['issuer'], 'audience' => json_encode($p['audience']), 'published_at' => now()]);
            foreach ($p['audience'] as $recipient) {
                if ((int) $recipient === (int) $doc->owner_id) {
                    continue;
                }
                $existing = DB::table('shares')->where('document_id', $doc->id)->where('recipient_id', $recipient)->first();
                DB::table('shares')->updateOrInsert(['document_id' => $doc->id, 'recipient_id' => $recipient],
                    ['permission' => 'Viewer', 'version_id' => $p['version_id'], 'expires_at' => now()->addDays(30), 'revoked_at' => null, 'memo_generated' => ! $existing || (bool) $existing->memo_generated, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        DB::table('documents')->where('id', $doc->id)->update($update);
        $doc->revision++;
        Access::audit($actor, $action.'.applied', $doc, $p);
        Access::event($doc, $action.'.applied');
    }
}
