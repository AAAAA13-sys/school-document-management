<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class WorkspaceRegistry
{
    public static function query(User $actor, bool $activeOnly = true): Builder
    {
        $query = DB::table('workspaces')->where('school_id', $actor->school_id)->where('campus', $actor->campus);
        if ($activeOnly) {
            $query->where('status', 'active');
        }
        if (! $actor->isSuperAdmin()) {
            $query->where(function (Builder $q) use ($actor) {
                $q->whereIn('id', DB::table('workspace_memberships')->select('workspace_id')->where('user_id', $actor->id));
                // Preserve the explicitly assigned primary scope for existing accounts.
                if ($actor->sourceCode()) {
                    $q->orWhere('code', $actor->sourceCode());
                }
            });
        }
        return $query;
    }

    public static function labels(?User $actor = null, bool $erpOnly = false, bool $activeOnly = true): array
    {
        $query = $actor ? self::query($actor, $activeOnly) : DB::table('workspaces')->when($activeOnly, fn ($q) => $q->where('status', 'active'));
        return $query->when($erpOnly, fn ($q) => $q->where('kind', 'erp'))->orderBy('name')->pluck('name', 'code')->all();
    }

    public static function codes(?User $actor = null, bool $erpOnly = false, bool $activeOnly = true): array
    {
        return array_keys(self::labels($actor, $erpOnly, $activeOnly));
    }

    public static function assign(User $user, string $code): void
    {
        $workspace = DB::table('workspaces')->where('school_id', $user->school_id)->where('campus', $user->campus)->where('code', $code)->where('status', 'active')->first();
        abort_unless($workspace, 422, 'Select an active workspace in the account scope.');
        DB::table('workspace_memberships')->updateOrInsert(['workspace_id' => $workspace->id, 'user_id' => $user->id], ['created_at' => now(), 'updated_at' => now()]);
    }

    public static function assertErp(object $connector): void
    {
        abort_unless(DB::table('workspaces')->where('school_id', $connector->school_id)->where('campus', $connector->campus)->where('code', $connector->source)->where('kind', 'erp')->where('status', 'active')->exists(), 403, 'Connector workspace is not an active ERP workspace.');
    }
}
