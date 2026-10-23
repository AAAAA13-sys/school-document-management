<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReadRequest;
use App\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WorkspaceController extends Controller
{
    public function personal(ReadRequest $request, string $section = 'overview')
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        abort_unless(in_array($section, ['overview', 'documents']), 404);

        return $this->index($request, 'DMS', $section);
    }

    public function index(ReadRequest $request, string $source, string $section = 'overview')
    {
        $source = strtoupper($source);
        $user = $request->user();
        abort_unless($user->canAccessSource($source) || ($source === 'DMS' && $user->isSuperAdmin()), 403);
        abort_unless(in_array($section, ['overview', 'documents', 'history', 'snapshots']), 404);
        abort_if($user->isStaff() && in_array($section, ['history', 'snapshots']), 403);
        $view = $request->input('view') ?: 'all';
        $base = Access::documents($user);
        if ($view !== 'shared' || $source === 'DMS') {
            $base->where('source', $source);
        }
        $foldersForSharing = Access::inheritedFolderIds($user);
        $stats = ['documents' => (clone $base)->whereNull('trashed_at')->count(),
            'blocked' => DB::table('document_versions')->whereIn('document_id', (clone $base)->select('id'))->where('scan_status', '!=', 'Clean')->count(),
            'shared' => (clone $base)->where(fn ($q) => $q->whereIn('id', DB::table('shares')->select('document_id')->whereNull('revoked_at')->where(fn ($s) => $s->whereNull('expires_at')->orWhere('expires_at', '>', now())))->orWhereIn('folder_id', $foldersForSharing))->count(),
            'history' => $user->isStaff() ? null : Access::scope(DB::table('history_receipts'), $user)->where('source', $source)->count()];

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
                ->when($user->isSuperAdmin() && $source === 'DMS', fn ($q) => $q->where('owner_id', $user->id))
                ->where('source', $source)->first() ?? abort(404);
            $base->where('folder_id', $folder->id);
            if ($folder->parent_id) {
                $parentFolder = Access::folders($user)->where('id', $folder->parent_id)->where('source', $source)->first();
            }
        }
        $canManageFolder = $folder && ($folder->owner_id === $user->id || Access::managesFolder($user, $folder));
        $folderShares = collect();
        $folderRecipients = collect();
        if ($canManageFolder) {
            $folderShares = DB::table('folder_shares')->join('users', 'users.id', '=', 'folder_shares.recipient_id')
                ->where('folder_id', $folder->id)->select('folder_shares.*', 'users.name', 'users.email')->orderByDesc('folder_shares.id')->get();
            $folderRecipients = Access::scope(DB::table('users'), $user)->where('active', true)->where('id', '!=', $user->id)
                // Named shares are the documented exception to ERP source isolation.
                ->orderBy('name')->get(['id', 'name', 'email']);
        }
        if ($section === 'documents' && !$folder && !$request->filled('q') && in_array($view, ['all','mine'])) {
            $base->whereNull('folder_id');
        }
        $documents = $base->orderByDesc('updated_at')->paginate(12)->withQueryString();
        $versions = DB::table('document_versions')->whereIn('document_id', $documents->pluck('id'))->orderBy('number')->get()->keyBy('document_id');
        foreach ($documents as $item) {
            if ($item->owner_id === $user->id || (! $user->isStaff() && $user->canAccessSource($item->source))) {
                continue;
            }
            $grant = Access::grant($user, $item);
            if ($grant?->version_id) {
                $versions[$item->id] = DB::table('document_versions')->where('id', $grant->version_id)->first();
            }
        }
        $starred = DB::table('stars')->where('user_id', $user->id)->pluck('document_id')->all();
        $folders = Access::folders($user)->where('source', $source)
            ->when($user->isSuperAdmin() && $source === 'DMS', fn ($q) => $q->where('owner_id', $user->id))
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

        return view('workspace', compact('source', 'section', 'stats', 'documents', 'versions', 'folders', 'folder', 'parentFolder', 'starred', 'view', 'history', 'canManageFolder', 'folderShares', 'folderRecipients'));
    }

    public function show(ReadRequest $request, string $document)
    {
        $doc = Access::document($request->user(), $document);
        $source = $doc->source;
        $section = 'documents';
        $versions = DB::table('document_versions')->where('document_id', $document)->orderByDesc('number')->get();
        $user = $request->user();
        $grant = Access::effectiveGrant($user, $doc);
        if ($grant?->version_id && $doc->owner_id !== $user->id && ($user->isStaff() || ! $user->canAccessSource($source))) {
            $versions = $versions->where('id', $grant->version_id);
        }
        $comments = DB::table('comments')->join('users', 'users.id', '=', 'comments.user_id')->where('document_id', $document)->select('comments.*', 'users.name')->orderByDesc('comments.id')->get();
        $shares = DB::table('shares')->join('users', 'users.id', '=', 'shares.recipient_id')->where('document_id', $document)->select('shares.*', 'users.name', 'users.email')->get();
        $recipients = Access::scope(DB::table('users'), $user)->where('active', true)->where('id', '!=', $user->id)->orderBy('name')->get(['id', 'name', 'email']);
        $owner = $doc->owner_id === $user->id || Access::manages($user, $doc);
        if (! $owner) {
            $recipients = collect();
        }
        $editor = $owner || ($grant?->permission === 'Editor' && ! $grant?->version_id);
        $commenter = $owner || in_array($grant?->permission, ['Commenter', 'Editor']);
        if ($user->isSuperAdmin() && $source !== 'DMS') {
            $owner = $editor = $commenter = false;
        }
        $folders = Access::scope(DB::table('folders'), $user)->where('source', $source)->when(! ($user->isSuperAdmin() || $user->isAdmin()) || $source === 'DMS', fn ($q) => $q->where('owner_id', $user->id))->get();
        $activity = Access::scope(DB::table('audit_entries'), $user)->where('document_id', $document)->orderByDesc('id')->limit(20)->get();

        return view('document', compact('doc', 'source', 'section', 'versions', 'comments', 'shares', 'recipients', 'owner', 'editor', 'commenter', 'folders', 'activity'));
    }

    public function scanStatus(Request $request, string $document)
    {
        $user = $request->user();
        $doc = Access::document($user, $document);
        $query = DB::table('document_versions')->where('document_id', $document);
        if ($doc->owner_id !== $user->id && ! $user->isSuperAdmin() && ! (! $user->isStaff() && $user->canAccessSource($doc->source))) {
            $grant = Access::grant($user, $doc);
            if ($grant?->version_id) {
                $query->where('id', $grant->version_id);
            }
        }

        return response()->json(['versions' => $query->get(['id', 'scan_status'])])->header('Cache-Control', 'private, no-store');
    }
}
