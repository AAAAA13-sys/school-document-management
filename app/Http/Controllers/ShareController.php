<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActionRequest;
use App\Services\Access;
use App\Services\SensitiveChangeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ShareController extends Controller
{
    public function create(ActionRequest $request, string $document)
    {
        $payload = $request->validated();
        if ($request->has('folder_inheritance')) {
            $payload['folder_inheritance'] = $request->boolean('folder_inheritance');
        }
        $result = app(SensitiveChangeService::class)->submit($request->user(), Access::document($request->user(), $document), 'share.grant', $payload);
        $message = $result['status'] === 'pending' ? 'Access request submitted. Existing access is unchanged until approval.' : 'Access grant saved.';

        return $request->expectsJson() ? response()->json($result + ['message' => $message], $result['status'] === 'pending' ? 202 : 200) : back()->with('status', $message);
    }

    public function revoke(ActionRequest $request, string $document, string $share)
    {
        $result = app(SensitiveChangeService::class)->submit($request->user(), Access::document($request->user(), $document), 'share.revoke', $request->validated() + ['share_id' => $share]);
        $message = $result['status'] === 'pending' ? 'Revocation request submitted. Existing access is unchanged until approval.' : 'Access revoked.';

        return $request->expectsJson() ? response()->json($result + ['message' => $message], $result['status'] === 'pending' ? 202 : 200) : back()->with('status', $message);
    }

    public function comment(ActionRequest $request, string $document)
    {
        DB::transaction(function () use ($request, $document) {
            $user = $request->user();
            Access::document($user, $document);
            $doc = DB::table('documents')->where('id', $document)->lockForUpdate()->first();
            Gate::forUser($user)->authorize('document.comment', $doc);
            abort_if($doc->trashed_at, 409);
            DB::table('comments')->insert(['document_id' => $document, 'user_id' => $user->id, 'body' => $request->validated('body'), 'created_at' => now()]);
            Access::audit($user, 'comment.created', $doc);
        });

        return $request->expectsJson() ? response()->json(['message' => 'Comment added.'], 201) : back()->with('status', 'Comment added.');
    }
}
