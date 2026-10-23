<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Move-safety for documents between shared folders (Phase 5, item 3).
 *
 * Access is derived from the current folder, so a move changes who can open the
 * document. This compares the effective folder audience before and after so the
 * owner can be warned instead of silently broadening access.
 */
class DocumentMoves
{
    /**
     * @return array{gained: array<int, string>, reduced: array<int, string>, broadened: bool}
     */
    public static function audienceDelta(object $document, ?string $destinationFolderId, ?string $newClassification = null): array
    {
        // Folder grants only widen Internal documents. For Confidential and
        // Restricted documents a move cannot broaden access through a folder.
        $oldInternal = $document->classification === 'Internal';
        $newInternal = ($newClassification ?? $document->classification) === 'Internal';
        $before = $oldInternal ? Access::folderAudience($document->folder_id) : [];
        $after = $newInternal ? Access::folderAudience($destinationFolderId) : [];
        $candidates = array_values(array_unique(array_merge(array_keys($before), array_keys($after))));

        // Recipients whose access does not depend on the folder: explicit document
        // grants always win, and source-wide oversight already sees the document.
        $explicit = DB::table('shares')->where('document_id', $document->id)->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->pluck('permission', 'recipient_id')->all();
        $alreadySees = $candidates ? DB::table('users')->whereIn('id', $candidates)
            ->where(fn ($q) => $q->where('role', 'super_admin')
                ->orWhere(fn ($r) => $r->where('role', 'admin')->where('source', $document->source)))
            ->pluck('id')->all() : [];

        $ignored = fn ($id) => (int) $id === (int) $document->owner_id || isset($explicit[$id]) || in_array($id, $alreadySees);
        $gained = [];
        $reduced = [];
        foreach ($after as $id => $permission) {
            if ($ignored($id)) {
                continue;
            }
            if (Access::permissionRank($permission) > Access::permissionRank($before[$id] ?? null)) {
                $gained[$id] = $permission;
            }
        }
        foreach ($before as $id => $permission) {
            if ($ignored($id)) {
                continue;
            }
            if (Access::permissionRank($permission) > Access::permissionRank($after[$id] ?? null)) {
                $reduced[$id] = $permission;
            }
        }

        return ['gained' => $gained, 'reduced' => $reduced, 'broadened' => $gained !== []];
    }
}
