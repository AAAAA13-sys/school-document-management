<?php

namespace App\Http\Middleware;

use App\Services\Access;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RepositoryReadOnly
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        abort_if($user?->role === 'staff' && !$request->isMethodSafe() && ($request->is('folders', 'folders/*', 'documents/batch') || $request->is('documents/*/shares', 'documents/*/shares/*')), 403, 'Staff cannot manage folders or access permissions.');
        if ($user?->role === 'admin' && !$request->isMethodSafe()
            && $request->is('documents', 'documents/*', 'folders', 'folders/*')) {
            $document=$request->route('document');
            $folder=$request->route('folder');
            $allowed=$document || $folder
                ? Access::scope(DB::table($document ? 'documents' : 'folders'),$user)->where('id',$document ?: $folder)->where('source',$user->source)->exists()
                : $request->input('source') === $user->source;
            abort_unless($allowed,403,'Admins manage content only in their assigned workspace.');
        }
        if ($user?->role === 'super_admin' && ! $request->isMethodSafe()
            && $request->is('documents', 'documents/*', 'folders', 'folders/*')) {
            $document = $request->route('document');
            $folder = $request->route('folder');
            if ($document || $folder) {
                $allowed = Access::scope(DB::table($document ? 'documents' : 'folders'), $user)
                    ->where('id', $document ?: $folder)->where('source', 'DMS')->where('owner_id', $user->id)->exists();
            } else {
                $allowed = $request->input('source') === 'DMS';
            }
            abort_unless($allowed, 403, 'ERP repositories are read-only. Manage your files in My workspace.');
        }

        return $next($request);
    }
}
