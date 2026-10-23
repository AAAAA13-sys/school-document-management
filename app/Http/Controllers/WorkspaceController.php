<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReadRequest;
use App\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class WorkspaceController extends Controller
{
    public function index(ReadRequest $request, string $source, string $section = 'overview')
    {
        $source = strtoupper($source);
        $user = $request->user();
        abort_unless(\App\Services\Access::scope(DB::table('workspaces'), $user)->where('code', $source)->where('status', 'active')->exists(), 404);
        Gate::forUser($user)->authorize('workspaces.access', $source);
        abort_unless(in_array($section, ['overview', 'documents', 'history', 'snapshots']), 404);
        if (in_array($section, ['history', 'snapshots'])) {
            Gate::forUser($user)->authorize('workspace.history');
        }
        $view = $request->input('view') ?: 'all';
        $base = Access::documents($user);
        if ($view !== 'shared') {
            $base->where('source', $source);
        }
        $foldersForSharing = Access::inheritedFolderIds($user);
        $stats = ['documents' => (clone $base)->whereNull('trashed_at')->count(),
            'blocked' => DB::table('document_versions')->whereIn('document_id', (clone $base)->select('id'))->where('scan_status', '!=', 'Clean')->count(),
            'shared' => (clone $base)->where(fn ($q) => $q->whereIn('id', DB::table('shares')->select('document_id')->whereNull('revoked_at')->where(fn ($s) => $s->whereNull('expires_at')->orWhere('expires_at', '>', now())))->orWhereIn('folder_id', $foldersForSharing))->count(),
            'history' => Gate::forUser($user)->allows('workspace.history')
                ? Access::scope(DB::table('history_receipts'), $user)->where('source', $source)->count()
                : null];

        if ($view === 'trash') {
            $base->whereNotNull('trashed_at');
        } else {
            $base->whereNull('trashed_at');
        }
        if ($view === 'mine') {
            $base->where('owner_id', $user->id);
        }
        if ($view === 'shared') {
            $base->where(fn ($q) => $q->whereIn('id', DB::table('shares')->select('document_id')->where('recipient_id', $user->id)->whereNull('revoked_at')->where(fn ($s) => $s->whereNull('expires_at')->orWhere('expires_at', '>', now())))->orWhereIn('folder_id', $foldersForSharing));
        }
        if ($view === 'starred') {
            $base->whereIn('id', DB::table('stars')->select('document_id')->where('user_id', $user->id));
        }
        if ($request->filled('q')) {
            $base->where(fn ($q) => $q->where('title', 'like', '%'.$request->input('q').'%')->orWhere('reference', 'like', '%'.$request->input('q').'%'));
        }
        $folder = null;
        $parentFolder = null;
        if ($request->filled('folder')) {
            $folder = Access::folders($user)->where('id', $request->input('folder'))
                ->where('source', $source)->first() ?? abort(404);
            $base->where('folder_id', $folder->id);
            if ($folder->parent_id) {
                $parentFolder = Access::folders($user)->where('id', $folder->parent_id)->where('source', $source)->first();
            }
        }
        $canManageFolder = $folder && Gate::forUser($user)->allows('folder.update', $folder);
        $folderShares = collect();
        $folderRecipients = collect();
        if ($canManageFolder) {
            $folderShares = DB::table('folder_shares')->join('users', 'users.id', '=', 'folder_shares.recipient_id')
                ->where('folder_id', $folder->id)->select('folder_shares.*', 'users.name', 'users.email')->orderByDesc('folder_shares.id')->get();
            $folderRecipients = Access::scope(DB::table('users'), $user)->where('active', true)->where('id', '!=', $user->id)
                // Named shares are the documented exception to ERP source isolation.
                ->orderBy('name')->get(['id', 'name', 'email']);
        }
        if ($section === 'documents' && ! $folder && ! $request->filled('q') && in_array($view, ['all', 'mine'])) {
            $base->whereNull('folder_id');
        }
        $documents = $base->orderByDesc('updated_at')->paginate(12)->withQueryString();
        $versions = DB::table('document_versions')->whereIn('document_id', $documents->pluck('id'))->orderBy('number')->get()->keyBy('document_id');
        foreach ($documents as $item) {
            $memoPin = \App\Services\MemoService::circulatedVersion($user, $item);
            if ($memoPin) {
                $versions[$item->id] = DB::table('document_versions')->where('id', $memoPin)->first();
                continue;
            }
            if ($item->owner_id === $user->id || Gate::forUser($user)->allows('document.all-versions', $item)) {
                continue;
            }
            $grant = Access::grant($user, $item);
            if ($grant?->version_id) {
                $versions[$item->id] = DB::table('document_versions')->where('id', $grant->version_id)->first();
            }
        }
        $starred = DB::table('stars')->where('user_id', $user->id)->pluck('document_id')->all();
        $folders = Access::folders($user)->where('source', $source)
            ->select('folders.*')->selectSub(Access::documents($user)->whereColumn('folder_id', 'folders.id')->whereNull('trashed_at')->selectRaw('COUNT(*)'), 'document_count')
            ->orderBy('name')->get();
        $stats['folders'] = $folders->count();
        $history = null;
        if (in_array($section, ['history', 'snapshots'])) {
            $query = Access::scope(DB::table($section === 'history' ? 'history_receipts' : 'source_snapshots'), $user)->where('source', $source);
            if ($request->filled('q')) {
                $query->where('record_id', 'like', '%'.$request->input('q').'%');
            }
            $history = $query->orderByDesc('id')->paginate(20)->withQueryString();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'source' => $source,
                'section' => $section,
                'stats' => $stats,
                'view' => $view,
                'folder' => $folder,
                'parent_folder' => $parentFolder,
                'folders' => $folders,
                'documents' => $documents,
                'versions' => $versions,
                'starred' => $starred,
                'history' => $history,
            ]);
        }

        return view('workspace', compact('source', 'section', 'stats', 'documents', 'versions', 'folders', 'folder', 'parentFolder', 'starred', 'view', 'history', 'canManageFolder', 'folderShares', 'folderRecipients'));
    }

    public function show(ReadRequest $request, string $document)
    {
        $user = $request->user();
        $doc = Access::document($user, $document);
        Gate::forUser($user)->authorize('document.view', $doc);
        $doc->owner_name = $doc->owner_id ? Access::scope(DB::table('users'), $user)->where('id', $doc->owner_id)->value('name') : null;
        $source = $doc->source;
        $section = 'documents';
        $versions = DB::table('document_versions')->where('document_id', $document)->orderByDesc('number')->get();
        $grant = Access::effectiveGrant($user, $doc);
        if ($grant?->version_id && $doc->owner_id !== $user->id
            && ! Gate::forUser($user)->allows('document.all-versions', $doc)) {
            $versions = $versions->where('id', $grant->version_id);
        }
        $memoPin = \App\Services\MemoService::circulatedVersion($user, $doc);
        if ($memoPin) $versions = $versions->where('id', $memoPin);
        $comments = DB::table('comments')->join('users', 'users.id', '=', 'comments.user_id')->where('document_id', $document)->select('comments.*', 'users.name')->orderByDesc('comments.id')->get();
        $shares = DB::table('shares')->join('users', 'users.id', '=', 'shares.recipient_id')->where('document_id', $document)->select('shares.*', 'users.name', 'users.email')->get();
        $owner = Gate::forUser($user)->allows('document.manage', $doc);
        $editor = Gate::forUser($user)->allows('document.edit', $doc);
        $commenter = Gate::forUser($user)->allows('document.comment', $doc);
        $sharer = Gate::forUser($user)->allows('document.share', $doc);
        $classifications = collect(['Internal', 'Confidential', 'Restricted'])
            ->filter(fn (string $level) => Gate::forUser($user)->allows('document.classify', [$doc, $level]))
            ->values();
        $recipients = $sharer
            ? Access::scope(DB::table('users'), $user)->where('active', true)->where('id', '!=', $user->id)->orderBy('name')->get(['id', 'name', 'email'])
            : collect();
        $folders = Access::scope(DB::table('folders'), $user)->where('source', $source)
            ->when($user->isStaff(), fn ($q) => $q->where('owner_id', $user->id))
            ->get();
        $activity = Access::scope(DB::table('audit_entries'), $user)->where('document_id', $document)->orderByDesc('id')->limit(20)->get();

        if ($request->expectsJson()) {
            return response()->json([
                'document' => $doc,
                'source' => $source,
                'section' => $section,
                'versions' => $versions->values(),
                'comments' => $comments,
                'shares' => $shares,
                'recipients' => $recipients,
                'permissions' => [
                    'can_manage' => (bool) $owner,
                    'can_edit' => (bool) $editor,
                    'can_comment' => (bool) $commenter,
                    'can_share' => (bool) $sharer,
                    'classifications' => $classifications,
                ],
                'folders' => $folders,
                'activity' => $activity,
                'grant' => $grant,
            ]);
        }

        return view('document', compact('doc', 'source', 'section', 'versions', 'comments', 'shares', 'recipients', 'owner', 'editor', 'commenter', 'sharer', 'classifications', 'folders', 'activity', 'grant'));
    }

    public function scanStatus(Request $request, string $document)
    {
        $user = $request->user();
        $doc = Access::document($user, $document);
        Gate::forUser($user)->authorize('document.view', $doc);
        $query = DB::table('document_versions')->where('document_id', $document);
        if ($doc->owner_id !== $user->id && ! Gate::forUser($user)->allows('document.all-versions', $doc)) {
            $grant = Access::grant($user, $doc);
            if ($grant?->version_id) {
                $query->where('id', $grant->version_id);
            }
        }

        $memoPin = \App\Services\MemoService::circulatedVersion($user, $doc);
        if ($memoPin) $query->where('id', $memoPin);
        return response()->json(['versions' => $query->get(['id', 'scan_status'])])->header('Cache-Control', 'private, no-store');
    }
}
