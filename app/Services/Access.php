<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class Access
{
    public static function scope(Builder $query, object $actor, string $prefix = ''): Builder
    {
        return $query->where($prefix.'school_id', $actor->school_id)->where($prefix.'campus', $actor->campus);
    }

    public static function administrationScope(Builder $query, object $actor, string $prefix = ''): Builder
    {
        return self::scope($query, $actor, $prefix)->when($actor->role === 'admin', fn ($q) => $q->where($prefix.'source', $actor->source));
    }

    public static function manages(User $user, object $document): bool
    {
        return $user->role === 'admin' && $user->school_id === $document->school_id
            && $user->campus === $document->campus && $user->source === $document->source;
    }

    public static function documents(User $user): Builder
    {
        return self::scope(DB::table('documents'), $user)->where(function (Builder $q) use ($user) {
            $q->where('owner_id', $user->id);
            if ($user->role !== 'staff') {
                $q->orWhereIn('source', $user->sources());
            }
            $q->orWhereIn('id', DB::table('shares')->select('document_id')->where('recipient_id', $user->id)
                ->whereNull('revoked_at')->where(fn ($s) => $s->whereNull('expires_at')->orWhere('expires_at', '>', now())));
        });
    }

    public static function document(User $user, string $id): object
    {
        return self::documents($user)->where('id', $id)->first() ?? abort(404);
    }

    public static function grant(User $user, object $document): ?object
    {
        return DB::table('shares')->where('document_id', $document->id)->where('recipient_id', $user->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
    }

    public static function owner(User $user, object $document): void
    {
        abort_unless($document->owner_id === $user->id || self::manages($user, $document), 403);
    }

    public static function edit(User $user, object $document): void
    {
        if ($document->owner_id === $user->id || self::manages($user, $document)) {
            return;
        }
        $grant = self::grant($user, $document);
        abort_unless($grant && $grant->permission === 'Editor' && ! $grant->version_id, 403);
    }

    public static function audit(object $actor, string $action, ?object $document = null, array $details = []): void
    {
        DB::table('audit_entries')->insert(['school_id' => $actor->school_id, 'campus' => $actor->campus,
            'source' => $document?->source ?? $actor->source ?? null, 'actor_id' => $actor instanceof User ? $actor->id : null,
            'connector_id' => $actor instanceof User ? null : ($actor->id ?? null), 'action' => $action,
            'document_id' => $document?->id, 'details' => json_encode($details), 'created_at' => now()]);
    }

    public static function event(object $document, string $type): void
    {
        DB::table('integration_events')->insert(['school_id' => $document->school_id, 'campus' => $document->campus,
            'source' => $document->source, 'document_id' => $document->id, 'type' => $type, 'revision' => $document->revision, 'created_at' => now()]);
    }
}
