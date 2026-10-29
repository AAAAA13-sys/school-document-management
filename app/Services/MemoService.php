<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class MemoService
{
    public static function validatePublication(User $actor, object $doc, array $p): void
    {
        abort_if($doc->classification === 'Restricted' && ! $actor->isSuperAdmin(), 403, 'Restricted memo circulation requires Super Admin authority.');
        foreach (['type' => 100, 'reference' => 100, 'issuer' => 200] as $field => $limit) {
            abort_unless(is_string($p[$field] ?? null) && trim($p[$field]) !== '' && mb_strlen($p[$field]) <= $limit, 422);
        }
        abort_unless(DB::table('document_versions')->where('id', $p['version_id'] ?? '')->where('document_id', $doc->id)->where('scan_status', 'Clean')->exists(), 422, 'Publication requires a Clean document version.');
        abort_unless(is_array($p['audience'] ?? null) && count($p['audience']) > 0 && count($p['audience']) <= 500, 422);
        $ids = array_unique($p['audience']);
        abort_unless(Access::scope(DB::table('users'), $actor)->whereIn('id', $ids)->where('active', true)->count() === count($ids), 422, 'Audience must contain active users in this school and campus.');
    }

    public static function circulatedVersion(User $user, object $doc): ?string
    {
        if ($doc->owner_id === $user->id || $user->isSuperAdmin() || Access::manages($user, $doc)) {
            return null;
        }
        $memo = DB::table('memo_publications')->where('document_id', $doc->id)->first();

        return $memo?->version_id;
    }
}
