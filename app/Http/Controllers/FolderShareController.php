<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActionRequest;
use App\Models\User;
use App\Services\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class FolderShareController extends Controller
{
    public function create(ActionRequest $request, string $folder)
    {
        DB::transaction(function () use ($request, $folder) {
            $user = $request->user();
            $rowQuery = Access::scope(DB::table('folders'), $user)->where('id', $folder);
            if ($user->source) {
                $rowQuery->where('source', $user->source);
            }
            $row = $rowQuery->lockForUpdate()->first() ?? abort(404);
            Gate::forUser($user)->authorize('folder.share', $row);
            $data = $request->validated();
            abort_if((int) $data['recipient_id'] === $user->id, 422, 'Choose another recipient.');
            $recipient = User::where('school_id', $user->school_id)->where('campus', $user->campus)
                ->where('id', $data['recipient_id'])->where('active', true)->first();
            abort_unless($recipient, 422, 'Recipient is outside your scope or inactive.');
            // Named shares are the documented exception to ERP source isolation.
            // The recipient must still share the school/campus scope above.
            DB::table('folder_shares')->updateOrInsert(
                ['folder_id' => $folder, 'recipient_id' => $data['recipient_id']],
                ['permission' => $data['permission'], 'expires_at' => $data['expires_at'] ?? null, 'revoked_at' => null, 'created_at' => now(), 'updated_at' => now()],
            );
            Access::audit($user, 'folder.shared', null, ['folder_id' => $folder, 'recipient_id' => $data['recipient_id'], 'permission' => $data['permission']], $row->source);
        });

        return back()->with('status', 'Folder access saved. Documents inside it are now shared with the inherited permission.');
    }

    public function revoke(ActionRequest $request, string $folder, string $share)
    {
        DB::transaction(function () use ($request, $folder, $share) {
            $user = $request->user();
            $rowQuery = Access::scope(DB::table('folders'), $user)->where('id', $folder);
            if ($user->source) {
                $rowQuery->where('source', $user->source);
            }
            $row = $rowQuery->lockForUpdate()->first() ?? abort(404);
            Gate::forUser($user)->authorize('folder.share', $row);
            abort_unless(DB::table('folder_shares')->where('id', $share)->where('folder_id', $folder)->exists(), 404);
            DB::table('folder_shares')->where('id', $share)->update(['revoked_at' => now(), 'updated_at' => now()]);
            // Explicit document grants stay intact: revoking one path must not silently
            // remove another legitimate grant.
            Access::audit($user, 'folder.revoked', null, ['folder_id' => $folder, 'folder_share_id' => $share], $row->source);
        });

        return back()->with('status', 'Folder access revoked. Independent document grants are unchanged.');
    }
}
