<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class Access
{
    public const PERMISSION_RANK = ['Viewer' => 1, 'Commenter' => 2, 'Editor' => 3];

    public static function scope(Builder $query, object $actor, string $prefix = ''): Builder
    {
        return $query->where($prefix.'school_id', $actor->school_id)->where($prefix.'campus', $actor->campus);
    }

    public static function administrationScope(Builder $query, object $actor, string $prefix = ''): Builder
    {
        return self::scope($query, $actor, $prefix)->when(
            ($actor->role ?? null) === Role::Admin->value,
            fn ($q) => $q->whereIn($prefix.'source', $actor instanceof User ? $actor->sources() : []),
        );
    }

    public static function manages(User $user, object $document): bool
    {
        return ($user->isSuperAdmin() || $user->isAdmin()) && $user->school_id === $document->school_id
            && $user->campus === $document->campus && $user->canAccessSource($document->source);
    }

    public static function managesFolder(User $user, object $folder): bool
    {
        return self::manages($user, $folder);
    }

    /**
     * The single classification/visibility scope for every document read path.
     *
     * Internal: active users in the document's source, plus Super Admin.
     * Confidential: owner, Admin of the source, Super Admin, or an explicit
     * document share. Folder grants never widen Confidential access.
     * Restricted: owner, Super Admin, or an explicit document share. Folder
     * grants never widen Restricted access.
     *
     * Connectors are source-bound integration actors: they can read Internal and
     * Confidential documents in their source, but never Restricted documents.
     */
    public static function visibleTo(Builder $query, object $actor, string $prefix = ''): Builder
    {
        if ($actor instanceof User) {
            if (! ($actor->active ?? true)) {
                return $query->whereRaw('0 = 1');
            }

            $query->where($prefix.'school_id', $actor->school_id)
                ->where($prefix.'campus', $actor->campus);

            if ($actor->isSuperAdmin()) {
                return $query;
            }

            return $query->where(function (Builder $q) use ($actor, $prefix) {
                // Owners can always see their own document.
                $q->where($prefix.'owner_id', $actor->id)
                    // Internal is source-wide for active staff/admin accounts.
                    ->orWhere(function (Builder $q) use ($actor, $prefix) {
                        $q->where($prefix.'classification', 'Internal')
                            ->whereIn($prefix.'source', $actor->sources());
                    });

                // An Admin can see Confidential documents in its own source.
                if ($actor->isAdmin()) {
                    $q->orWhere(function (Builder $q) use ($actor, $prefix) {
                        $q->where($prefix.'classification', 'Confidential')
                            ->whereIn($prefix.'source', $actor->sources());
                    });
                }

                // Named document shares cross source boundaries by design and
                // apply to every classification.
                $q->orWhereIn($prefix.'id', self::activeShares()->select('document_id')
                    ->where('recipient_id', $actor->id));

                // Folder grants are inherited, but only for Internal documents.
                // Confidential and Restricted need a document-level share.
                $folders = self::inheritedFolderIds($actor);
                if ($folders) {
                    $q->orWhere(function (Builder $q) use ($prefix, $folders) {
                        $q->where($prefix.'classification', 'Internal')
                            ->whereIn($prefix.'folder_id', $folders);
                    });
                }
            });
        }

        // Source-bound connector identities never receive Restricted documents.
        return $query->where($prefix.'school_id', $actor->school_id)
            ->where($prefix.'campus', $actor->campus)
            ->where($prefix.'source', $actor->source)
            ->whereIn($prefix.'classification', ['Internal', 'Confidential'])
            ->when(in_array($actor->source, ['OAS', 'ES'], true), function ($q) use ($prefix) {
                $q->where(function ($q) use ($prefix) {
                    $q->whereNull($prefix.'category')->orWhere(function ($q) use ($prefix) {
                        foreach (['medical', 'health', 'clinical', 'diagnos', 'vaccin'] as $term) {
                            $q->whereRaw('LOWER('.$prefix.'category) NOT LIKE ?', ['%'.$term.'%']);
                        }
                    });
                });
            });
    }

    public static function documents(User $user): Builder
    {
        return self::visibleTo(DB::table('documents'), $user);
    }

    public static function sharedDocuments(Builder $query, User $user, array $folderIds): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereIn('id', self::activeShares()->select('document_id')->where('recipient_id', $user->id))
            ->orWhereIn('folder_id', $folderIds));
    }

    public static function activeShares(): Builder
    {
        return DB::table('shares')->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public static function folders(User $user): Builder
    {
        $query = self::scope(DB::table('folders'), $user);
        if ($user->isSuperAdmin()) {
            return $query;
        }
        $folders = self::inheritedFolderIds($user);

        return $query->where(function (Builder $q) use ($user, $folders) {
            $q->where('owner_id', $user->id);
            if ($user->isAdmin()) {
                $q->orWhereIn('source', $user->sources());
            }
            if ($folders) {
                $q->orWhereIn('id', $folders);
            }
        });
    }

    public static function document(User $user, string $id): object
    {
        return self::documents($user)->where('id', $id)->first() ?? abort(404);
    }

    /**
     * Explicit per-document grant only. Version pinning is an explicit concept, so
     * inherited folder access is never returned here.
     */
    public static function grant(User $user, object $document): ?object
    {
        return self::activeShares()->where('document_id', $document->id)
            ->where('recipient_id', $user->id)->first();
    }

    /**
     * The grant that actually decides the user's permission: an explicit document
     * grant always wins, otherwise the nearest active ancestor folder grant.
     */
    public static function effectiveGrant(User $user, object $document): ?object
    {
        return self::grant($user, $document) ?? self::folderGrant($user, $document->folder_id, $document->classification ?? null);
    }

    public static function folderGrant(User $user, ?string $folderId, ?string $classification = null): ?object
    {
        if ($classification !== null && $classification !== 'Internal') {
            return null;
        }

        $chain = self::folderChain($folderId);
        if (! $chain) {
            return null;
        }
        $grants = DB::table('folder_shares')->where('recipient_id', $user->id)->whereIn('folder_id', $chain)
            ->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get()->keyBy('folder_id');

        $shareGrants = DB::table('shares as s')
            ->join('documents as d', 'd.id', '=', 's.document_id')
            ->where('s.recipient_id', $user->id)
            ->where('s.folder_inheritance', true)
            ->whereNull('s.revoked_at')
            ->where(fn ($q) => $q->whereNull('s.expires_at')->orWhere('s.expires_at', '>', now()))
            ->whereIn('d.folder_id', $chain)
            ->select('s.*', 'd.folder_id')
            ->get()
            ->keyBy('folder_id');

        foreach ($chain as $id) {
            if ($grants->has($id)) {
                $grant = $grants->get($id);
                // Folder grants are never version-pinned; callers share the explicit-grant contract.
                $grant->version_id = null;

                return $grant;
            }
            if ($shareGrants->has($id)) {
                $grant = $shareGrants->get($id);
                $grant->version_id = null;

                return $grant;
            }
        }

        return null;
    }

    /**
     * Folder IDs from the given folder up to the root. Bounded so a corrupted
     * parent chain cannot loop forever.
     *
     * @return array<int, string>
     */
    public static function folderChain(?string $folderId): array
    {
        $chain = [];
        $limit = (int) config('dms.folder_inheritance_max_depth', 50);
        while ($folderId && ! in_array($folderId, $chain, true) && count($chain) < $limit) {
            $chain[] = $folderId;
            $folderId = DB::table('folders')->where('id', $folderId)->value('parent_id');
        }

        return $chain;
    }

    /**
     * Every folder a grant can reach: granted folders plus all descendants. Used to
     * make inherited visibility a single indexed IN clause for listings.
     *
     * Group grants are intentionally not resolved here: organizational membership is
     * owned by the membership module, which can union its group folders into this list.
     *
     * @return array<int, string>
     */
    public static function inheritedFolderIds(User $user): array
    {
        // Memoise within a real HTTP request only: console and queue workers keep the
        // same request instance across jobs, so caching there could serve stale grants.
        $memo = app()->runningInConsole() ? null : request()->attributes;
        $key = 'dms.inherited_folders.'.$user->id;
        if ($memo?->has($key)) {
            return $memo->get($key);
        }
        $granted = DB::table('folder_shares')->where('recipient_id', $user->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('folder_id')->all();

        $fromShares = DB::table('shares as s')
            ->join('documents as d', 'd.id', '=', 's.document_id')
            ->where('s.recipient_id', $user->id)
            ->where('s.folder_inheritance', true)
            ->whereNull('s.revoked_at')
            ->where(fn ($q) => $q->whereNull('s.expires_at')->orWhere('s.expires_at', '>', now()))
            ->whereNotNull('d.folder_id')
            ->pluck('d.folder_id')->all();

        $granted = array_values(array_unique(array_merge($granted, $fromShares)));
        if (! $granted) {
            $memo?->set($key, []);

            return [];
        }
        $seen = array_fill_keys($granted, true);
        $frontier = $granted;
        $limit = (int) config('dms.folder_inheritance_max_depth', 50);
        for ($depth = 0; $frontier && $depth < $limit; $depth++) {
            $frontier = DB::table('folders')->whereIn('parent_id', $frontier)
                ->where('school_id', $user->school_id)->where('campus', $user->campus)
                ->whereNotIn('id', array_keys($seen))->pluck('id')->all();
            foreach ($frontier as $id) {
                $seen[$id] = true;
            }
        }

        $result = array_keys($seen);
        $memo?->set($key, $result);

        return $result;
    }

    public static function owner(User $user, object $document): void
    {
        abort_unless($document->owner_id === $user->id || self::manages($user, $document), 403);
    }

    /**
     * Effective folder audience using the same nearest-grant-wins rule as
     * folderGrant(), resolved for every recipient in one pass.
     *
     * If the membership module adds group grants, resolve group members here with the
     * same nearest-wins rule so move warnings keep matching real access.
     *
     * @return array<int, string> recipient id => permission
     */
    public static function folderAudience(?string $folderId): array
    {
        $chain = self::folderChain($folderId);
        if (! $chain) {
            return [];
        }
        $grants = DB::table('folder_shares')->whereIn('folder_id', $chain)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get(['folder_id', 'recipient_id', 'permission'])->groupBy('folder_id');
        $audience = [];
        foreach ($chain as $id) {
            foreach ($grants->get($id, []) as $grant) {
                if (! array_key_exists($grant->recipient_id, $audience)) {
                    $audience[$grant->recipient_id] = $grant->permission;
                }
            }
        }

        return $audience;
    }

    public static function permissionRank(?string $permission): int
    {
        return self::PERMISSION_RANK[$permission] ?? 0;
    }

    public static function edit(User $user, object $document): void
    {
        if ($document->owner_id === $user->id || self::manages($user, $document)) {
            return;
        }
        $grant = self::effectiveGrant($user, $document);
        abort_unless($grant && $grant->permission === 'Editor' && ! $grant->version_id, 403);
    }

    public static function audit(object $actor, string $action, ?object $document = null, array $details = [], ?string $source = null): void
    {
        DB::table('audit_entries')->insert(['school_id' => $actor->school_id, 'campus' => $actor->campus,
            'source' => $source ?? $document?->source ?? $actor->source, 'actor_id' => $actor instanceof User ? $actor->id : null,
            'connector_id' => $actor instanceof User ? null : ($actor->id ?? null), 'action' => $action,
            'document_id' => $document?->id, 'details' => json_encode($details), 'created_at' => now()]);
    }

    public static function event(object $document, string $type): void
    {
        DB::table('integration_events')->insert(['school_id' => $document->school_id, 'campus' => $document->campus,
            'source' => $document->source, 'document_id' => $document->id, 'type' => $type, 'revision' => $document->revision, 'created_at' => now()]);
    }
}
