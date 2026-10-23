<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Advisory duplicate-file detection (Phase 5, item 4).
 *
 * Matches are limited to documents the actor could already open through the
 * normal listing rules, so an unauthorized user never learns that a hidden
 * document exists. Checksums, storage keys and filenames are never disclosed.
 */
class DuplicateDetection
{
    /** Maximum number of existing documents summarised back to the caller. */
    public const LIMIT = 5;

    /**
     * @return array<int, array{document_id: string, title: string, version_number: int, uploaded_at: string, can_add_version: bool}>
     */
    public static function matches(object $actor, string $checksum, ?string $exceptDocumentId = null): array
    {
        $query = DB::table('document_versions as v')
            ->join('documents as d', 'd.id', '=', 'v.document_id')
            ->where('v.checksum', $checksum)
            ->whereNull('d.trashed_at')
            ->when($exceptDocumentId, fn ($q) => $q->where('v.document_id', '!=', $exceptDocumentId))
            ->orderByDesc('v.cursor')
            ->limit(50);

        if ($actor instanceof User) {
            // Same visibility rules as workspace listings, shares and downloads.
            $query->whereIn('v.document_id', Access::documents($actor)->select('id'));
        } else {
            $query->where('d.school_id', $actor->school_id)
                ->where('d.campus', $actor->campus)
                ->where('d.source', $actor->source);
        }

        $rows = $query->get(['v.id as version_id', 'v.document_id', 'v.number', 'v.created_at',
            'd.title', 'd.owner_id', 'd.source', 'd.school_id', 'd.campus']);

        if ($rows->isEmpty()) {
            return [];
        }

        $pinned = $actor instanceof User
            ? DB::table('shares')->where('recipient_id', $actor->id)->whereNull('revoked_at')
                ->whereNotNull('version_id')->pluck('version_id', 'document_id')
            : collect();

        return $rows
            ->filter(fn ($row) => self::versionIsVisible($actor, $row, $pinned))
            ->unique('document_id')
            ->take(self::LIMIT)
            ->map(fn ($row) => [
                'document_id' => $row->document_id,
                'title' => $row->title,
                'version_number' => (int) $row->number,
                'uploaded_at' => $row->created_at,
                'can_add_version' => self::canAddVersion($actor, $row),
            ])
            ->values()
            ->all();
    }

    /**
     * A version-pinned grant only exposes that pinned version, so a match on any
     * other version of the same document must not be disclosed.
     */
    private static function versionIsVisible(object $actor, object $row, $pinned): bool
    {
        if (! $actor instanceof User) {
            return true;
        }
        if ($row->owner_id === $actor->id || Access::manages($actor, $row)) {
            return true;
        }
        if ($actor->isSuperAdmin() || $actor->canAccessSource($row->source)) {
            // Source-wide visibility makes a version-pinned cross-source grant
            // irrelevant for documents already inside the actor's source.
            return true;
        }
        $pinnedVersion = $pinned[$row->document_id] ?? null;

        return $pinnedVersion === null || $pinnedVersion === $row->version_id;
    }

    private static function canAddVersion(object $actor, object $row): bool
    {
        if (! $actor instanceof User) {
            return true;
        }
        if ($row->owner_id === $actor->id || Access::manages($actor, $row)) {
            return true;
        }

        return DB::table('shares')->where('document_id', $row->document_id)
            ->where('recipient_id', $actor->id)->whereNull('revoked_at')->whereNull('version_id')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where('permission', 'Editor')->exists();
    }
}
