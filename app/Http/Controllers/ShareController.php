<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActionRequest;
use App\Services\Access;
use Illuminate\Support\Facades\DB;

class ShareController extends Controller
{
    public function create(ActionRequest $request, string $document)
    {
        DB::transaction(function () use ($request, $document) {
            $user = $request->user();
            Access::document($user, $document);
            $doc = DB::table('documents')->where('id', $document)->lockForUpdate()->first();
            Access::owner($user, $doc);
            abort_if($doc->trashed_at, 409);
            $data = $request->validated();
            abort_if((int) $data['recipient_id'] === $user->id, 422, 'Choose another recipient.');
            abort_unless(Access::scope(DB::table('users'), $user)->where('id', $data['recipient_id'])->where('active', true)->exists(), 422, 'Recipient is outside your scope or inactive.');
            if (! empty($data['version_id'])) {
                abort_unless(DB::table('document_versions')->where('id', $data['version_id'])->where('document_id', $document)->exists(), 422, 'Version does not belong to this document.');
                abort_if($data['permission'] === 'Editor', 422, 'Pinned versions are immutable. Choose Viewer or Commenter.');
            }
            DB::table('shares')->updateOrInsert(['document_id' => $document, 'recipient_id' => $data['recipient_id']], $data + ['version_id' => null, 'expires_at' => null, 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            Access::audit($user, 'share.granted', $doc, ['recipient_id' => $data['recipient_id'], 'permission' => $data['permission']]);
        });

        return back()->with('status', 'Access grant saved.');
    }

    public function revoke(ActionRequest $request, string $document, string $share)
    {
        DB::transaction(function () use ($request, $document, $share) {
            $user = $request->user();
            Access::document($user, $document);
            $doc = DB::table('documents')->where('id', $document)->lockForUpdate()->first();
            Access::owner($user, $doc);
            abort_unless(DB::table('shares')->where('id', $share)->where('document_id', $document)->exists(), 404);
            DB::table('shares')->where('id', $share)->update(['revoked_at' => now(), 'updated_at' => now()]);
            Access::audit($user, 'share.revoked', $doc, ['share_id' => $share]);
        });

        return back()->with('status', 'Access revoked.');
    }

    public function comment(ActionRequest $request, string $document)
    {
        DB::transaction(function () use ($request, $document) {
            $user = $request->user();
            Access::document($user, $document);
            $doc = DB::table('documents')->where('id', $document)->lockForUpdate()->first();
            abort_if($doc->trashed_at, 409);
            $grant = Access::grant($user, $doc);
            abort_unless($doc->owner_id === $user->id || Access::manages($user, $doc) || in_array($grant?->permission, ['Commenter', 'Editor']), 403);
            DB::table('comments')->insert(['document_id' => $document, 'user_id' => $user->id, 'body' => $request->validated('body'), 'created_at' => now()]);
            Access::audit($user, 'comment.created', $doc);
        });

        return back()->with('status', 'Comment added.');
    }
}
