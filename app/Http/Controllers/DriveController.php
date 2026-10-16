<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DocumentService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DriveController extends Controller
{
    public function __construct(private DocumentService $dms) {}

    private function actor(Request $r): object
    {
        $a = $this->dms->actor($r);
        abort_unless(! isset($a->source) && in_array($a->role, ['admin', 'registrar', 'hr', 'payroll', 'student', 'teacher', 'employee']), 403);

        return $a;
    }

    private function folder(string $id, object $a): object
    {
        $f = $this->dms->scope(DB::table('folders'), $a)->where('id', $id)->first();
        abort_unless($f, 404);

        return $f;
    }

    /** Own/institutional folder access, otherwise an active folder grant. Returns [folder, grant row|null]. */
    private function folderAccess(string $id, object $a): array
    {
        $f = $this->dms->scope(DB::table('folders'), $a)->where('id', $id)->first();
        if ($f) {
            return [$f, null];
        }
        $grant = $this->folderGrant($id, $a);
        $f = DB::table('folders')->where('id', $id)->first();
        abort_unless($f && $grant, 404);

        return [$f, $grant];
    }

    /** Strongest active grant the actor holds on this folder or on a folder above it. */
    private function folderGrant(string $id, object $a): ?object
    {
        $rank = ['viewer' => 1, 'commenter' => 2, 'editor' => 3];
        $granted = $this->dms->sharedFolders($a);
        $best = null;
        $folder = DB::table('folders')->where('id', $id)->first();
        $guard = 0;
        while ($folder && $guard++ < 64) {
            if (isset($granted[$folder->id])) {
                $rows = DB::table('folder_shares')->where('recipient_id', $a->id)->where('folder_id', $folder->id)
                    ->whereNull('revoked_at')->where('expires_at', '>', now())->get();
                foreach ($rows as $row) {
                    if (! $best || ($rank[$row->permission] ?? 0) > ($rank[$best->permission] ?? 0)) {
                        $best = $row;
                    }
                }
            }
            $folder = $folder->parent_id ? DB::table('folders')->where('id', $folder->parent_id)->first() : null;
        }

        return $best;
    }

    private function color(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        abort_unless(array_key_exists($value, config('dms.folder_colors')), 422, 'Choose one of the available folder colors.');

        return $value;
    }

    public function index(Request $r)
    {
        $a = $this->actor($r);
        $p = $r->validate(['folder' => 'nullable|uuid', 'location' => 'nullable|in:home,files,recent,starred,trash', 'search' => 'nullable|string|max:200', 'sort' => 'nullable|in:name,updated', 'page' => 'nullable|integer|min:1']);
        $location = $p['location'] ?? 'files';
        $folder = $p['folder'] ?? null;
        $crumbs = [];
        $grant = null;
        $subtree = null;
        if ($folder) {
            [$f, $grant] = $this->folderAccess($folder, $a);
            array_unshift($crumbs, ['id' => $f->id, 'name' => $f->name, 'source' => $f->source, 'color' => $f->color, 'revision' => $f->revision, 'shared' => $grant !== null]);
            if ($grant === null) {
                do {
                    $f = $f->parent_id ? $this->folder($f->parent_id, $a) : null;
                    if ($f) {
                        array_unshift($crumbs, ['id' => $f->id, 'name' => $f->name, 'source' => $f->source, 'color' => $f->color, 'revision' => $f->revision, 'shared' => false]);
                    }
                } while ($f);
            } else {
                $subtree = $this->dms->folderSubtree($folder);
            }
        }
        if ($subtree) {
            $q = DB::table('documents')->where('school_id', $a->school_id)->where('campus', $a->campus)
                ->where('source', DB::table('folders')->where('id', $folder)->value('source'))
                ->whereIn('folder_id', $subtree)->whereNull('trashed_at');
        } else {
            $q = $this->dms->scope(DB::table('documents'), $a);
            $location === 'trash' ? $q->whereNotNull('trashed_at') : $q->whereNull('trashed_at');
            if ($location === 'starred') {
                $q->whereIn('id', DB::table('document_stars')->where('user_id', $a->id)->select('document_id'));
            }
            if ($location === 'files' && empty($p['search'])) {
                $q->where('folder_id', $folder);
            }
            if (! empty($p['search'])) {
                $q->where('title', 'like', '%'.$p['search'].'%');
            }
        }
        ($p['sort'] ?? 'updated') === 'name' ? $q->orderBy('title') : $q->orderByDesc('updated_at');
        $items = $q->orderBy('id')->paginate(24);
        $stars = DB::table('document_stars')->where('user_id', $a->id)->pluck('document_id')->all();
        $ownerName = $grant && $f && $f->owner_user_id ? (User::find($f->owner_user_id)->name ?? null) : null;
        $items->getCollection()->transform(function ($d) use ($stars, $grant, $ownerName) {
            $d->starred = in_array($d->id, $stars);
            $d->current = $this->dms->latest($d->id);
            $d->shared = $grant !== null;
            $d->permission = $grant->permission ?? null;
            if ($grant) {
                $d->grant_id = $grant->id;
                $d->shared_by = $ownerName;
                $d->share_url = '/workspace/folder-shares/'.$grant->id.'/documents/'.$d->id;
            }
            unset($d->current->storage_key);

            return $d;
        });
        $folders = collect();
        if (in_array($location, ['home', 'files'])) {
            if ($subtree) {
                $folders = DB::table('folders')->where('parent_id', $folder)->orderBy('name')->get();
            } else {
                $folders = $this->dms->scope(DB::table('folders'), $a)->where('parent_id', $folder)->orderBy('name')->get();
                if ($folder === null) {
                    $folders = $folders->concat($this->dms->sharedFolderRoots($a)->each(function ($f) use ($a) {
                        $f->shared = true;
                        $f->permission = $this->dms->folderPermission($a, $f->id) ?? 'viewer';
                    }));
                }
            }
            $folders = $folders->when($p['search'] ?? null, fn ($q, $s) => $q->filter(fn ($f) => str_contains(mb_strtolower($f->name), mb_strtolower($s))))->unique('id')
                ->each(fn ($f) => $f->shared = $grant !== null || (bool) ($f->shared ?? false))->values();
        }

        return response()->json(['files' => $items, 'folders' => $folders, 'breadcrumbs' => $crumbs]);
    }

    public function folders(Request $r)
    {
        $a = $this->actor($r);
        $shared = $this->dms->sharedFolders($a);
        $own = $this->dms->scope(DB::table('folders'), $a)->orderBy('name')->get();
        $rows = $this->dms->sharedFolderRoots($a)->each(function ($f) use ($shared) {
            $f->shared = true;
            $f->permission = $shared[$f->id] ?? 'viewer';
        });

        return response()->json($own->concat($rows)->unique('id')->values());
    }

    public function folderDetails(Request $r, string $id)
    {
        $a = $this->actor($r);
        [$f, $grant] = $this->folderAccess($id, $a);
        $f->shared = $grant !== null;
        $f->permission = $grant->permission ?? null;
        $activity = DB::table('audit_entries')->where('school_id', $a->school_id)->where('campus', $a->campus)->where('detail', $id)->whereIn('action', ['Folder created','Folder renamed','Folder color changed'])->orderByDesc('id')->limit(50)->get(['action','actor','created_at']);
        return response()->json(['folder' => $f, 'activity' => $activity]);
    }

    public function createFolder(Request $r)
    {
        $a = $this->actor($r);
        $p = $r->validate(['name' => 'required|string|max:200', 'source' => 'required|string', 'parent_id' => 'nullable|uuid', 'color' => 'sometimes|nullable|string|max:20']);
        abort_unless(in_array($p['source'], $this->dms->sources($a)), 403);
        if (! empty($p['parent_id'])) {
            abort_unless($this->folder($p['parent_id'], $a)->source === $p['source'], 422, 'Folder source must match its parent.');
        }
        $color = $this->color($p['color'] ?? null);
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $p, $a, $color) {
            DB::table('folders')->insert(['id' => $id, 'owner_user_id' => $a->id, 'name' => $p['name'], 'source' => $p['source'], 'parent_id' => $p['parent_id'] ?? null, 'color' => $color, 'school_id' => $a->school_id, 'campus' => $a->campus, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->dms->log($a, 'Folder created', null, $id);
        });

        return response()->json(['id' => $id], 201);
    }

    public function updateFolder(Request $r, string $id)
    {
        $a = $this->actor($r);
        $p = $r->validate(['name' => 'sometimes|required|string|max:200', 'color' => 'sometimes|nullable|string|max:20', 'revision' => 'required|integer|min:1']);
        abort_unless(isset($p['name']) || array_key_exists('color', $p), 422, 'Provide a folder name or a folder color.');
        $color = array_key_exists('color', $p) ? $this->color($p['color']) : null;

        return DB::transaction(function () use ($a, $p, $id, $color) {
            $f = $this->folder($id, $a);
            $update = ['revision' => $f->revision + 1, 'updated_at' => now()];
            if (isset($p['name'])) {
                $update['name'] = $p['name'];
            }
            if (array_key_exists('color', $p)) {
                $update['color'] = $color;
            }
            abort_unless(DB::table('folders')->where('id', $id)->where('revision', $p['revision'])->update($update), 409, 'Folder changed. Refresh.');
            if (isset($p['name'])) {
                $this->dms->log($a, 'Folder renamed', null, $id);
            }
            if (array_key_exists('color', $p)) {
                $this->dms->log($a, 'Folder color changed', null, $id);
            }

            return response()->json(['ok' => true, 'color' => $update['color'] ?? $f->color, 'revision' => $f->revision + 1]);
        });
    }

    public function action(Request $r, string $id)
    {
        $a = $this->actor($r);
        $p = $r->validate(['action' => 'required|in:rename,move,star,trash,restore', 'revision' => 'required|integer|min:1', 'title' => 'required_if:action,rename|string|max:200', 'folder_id' => 'nullable|uuid']);

        return DB::transaction(function () use ($a, $p, $id) {
            $d = $this->dms->scope(DB::table('documents'), $a)->where('id', $id)->lockForUpdate()->first();
            abort_unless($d, 404);
            abort_unless($d->revision == $p['revision'], 409, 'Document changed. Refresh.');
            $action = $p['action'];
            abort_if($d->trashed_at && ! in_array($action, ['restore', 'star']), 409, 'Restore this document first.');
            if ($action === 'star') {
                $q = DB::table('document_stars')->where('user_id', $a->id)->where('document_id', $id);
                $q->exists() ? $q->delete() : DB::table('document_stars')->insert(['user_id' => $a->id, 'document_id' => $id]);
            } else {
                $update = ['revision' => $d->revision + 1, 'updated_at' => now()];
                if ($action === 'rename') {
                    $update = $this->dms->renameKeys($d, $p['title']) + $update;
                    $update['title'] = $p['title'];
                }
                if ($action === 'move') {
                    $folder = $p['folder_id'] ?? null;
                    if ($folder) {
                        abort_unless($this->folder($folder, $a)->source === $d->source, 422, 'Destination must belong to the same source system.');
                    } $update['folder_id'] = $folder;
                }
                if ($action === 'trash') {
                    $update['trashed_at'] = now();
                }
                if ($action === 'restore') {
                    $update['trashed_at'] = null;
                }
                abort_unless(DB::table('documents')->where('id', $id)->where('revision', $p['revision'])->update($update), 409);
                $d->revision++;
                $this->dms->event($d, 'document.'.$action, $this->dms->latest($id)->id);
            }
            $this->dms->log($a, 'Document '.$action, $id);

            return response()->json(['ok' => true]);
        });
    }

    public function preview(Request $r, string $id)
    {
        $a = $this->actor($r);
        $v = DB::table('document_versions')->where('id', $id)->first();
        abort_unless($v, 404);
        [$d, $permission] = $this->dms->documentAccess($v->document_id, $a);

        app(\App\Services\FileScanner::class)->requireClean($v);
        abort_unless(in_array($v->media_type, ['text/plain', 'application/pdf', 'image/png', 'image/jpeg']), 415);
        $path = Storage::disk('local')->path($v->storage_key);
        abort_unless(is_file($path), 503);
        abort_unless(hash_equals($v->checksum, hash_file('sha256', $path)), 409, 'File integrity check failed.');
        $this->dms->log($a, 'Document previewed', $v->document_id, 'Version '.$v->number);

        return response()->file($path, ['Content-Type' => $v->media_type, 'Content-Disposition' => 'inline', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "sandbox; default-src 'none'; frame-ancestors 'self'"]);
    }

    public function shares(Request $r, string $id)
    {
        $a = $this->actor($r);
        $this->dms->document($id, $a);

        return response()->json(DB::table('document_shares')->join('users', 'users.id', '=', 'document_shares.recipient_id')->where('document_id', $id)->where('created_by', $a->id)->get(['document_shares.*', 'users.email']));
    }

    public function share(Request $r, string $id)
    {
        $a = $this->actor($r);
        $p = $r->validate(['email' => 'required|email', 'permission' => 'sometimes|required|in:viewer,commenter,editor', 'expires_at' => 'required|date|after:now|before:'.now()->addDays(30)->toIso8601String()]);
        $d = $this->dms->document($id, $a);
        $recipient = User::where('email', $p['email'])->where('active', true)->first();
        abort_unless($recipient, 422, 'Active recipient not found.');
        abort_unless($recipient->school_id === $a->school_id && $recipient->campus === $a->campus && in_array($recipient->role, ['teacher','employee','admin','registrar','hr','payroll']), 403, 'Recipient must be an active workspace user in your school and campus.');
        $v = $this->dms->latest($id);
        app(\App\Services\FileScanner::class)->requireClean($v);

        $grant = (string) Str::uuid();
        DB::transaction(function () use ($grant, $id, $v, $a, $recipient, $p) {
            DB::table('document_shares')->insert(['id' => $grant, 'permission' => $p['permission'] ?? 'viewer', 'document_id' => $id, 'version_id' => $v->id, 'created_by' => $a->id, 'recipient_id' => $recipient->id, 'expires_at' => Carbon::parse($p['expires_at'])->utc(), 'created_at' => now()]);
            $this->dms->log($a, 'Document shared', $id, 'Grant '.$grant);
        });

        return response()->json(['url' => '/workspace/shared/'.$grant, 'id' => $grant], 201);
    }

    public function revoke(Request $r, string $id)
    {
        $a = $this->actor($r);
        $grant = DB::table('document_shares')->where('id', $id)->where('created_by', $a->id)->first();
        abort_unless($grant, 404);
        $this->dms->document($grant->document_id, $a);
        DB::transaction(function () use ($id, $a, $grant) {
            DB::table('document_shares')->where('id', $id)->update(['revoked_at' => now()]);
            $this->dms->log($a, 'Share revoked', $grant->document_id, $id);
        });

        return response()->json(['ok' => true]);
    }

    private function shareSheet(object $a, object $f): array
    {
        $owner = $f->owner_user_id ? User::find($f->owner_user_id) : null;

        return [
            'folder' => ['id' => $f->id, 'name' => $f->name, 'source' => $f->source],
            'owner' => $owner ? ['name' => $owner->name, 'email' => $owner->email] : null,
            'shares' => $this->dms->folderShareList($a, $f),
        ];
    }

    public function folderShares(Request $r, string $id)
    {
        $a = $this->actor($r);

        return response()->json($this->shareSheet($a, $this->folder($id, $a)));
    }

    public function shareFolder(Request $r, string $id)
    {
        $a = $this->actor($r);
        $f = $this->folder($id, $a);
        $p = $r->validate(['email' => 'required|email', 'permission' => 'sometimes|required|in:viewer,commenter', 'expires_at' => 'required|date|after:now|before:'.now()->addDays(30)->toIso8601String()]);
        $recipient = User::where('email', $p['email'])->where('active', true)->first();
        abort_unless($recipient, 422, 'Active recipient not found.');
        abort_unless($recipient->school_id === $a->school_id && $recipient->campus === $a->campus && in_array($recipient->role, ['teacher','employee','admin','registrar','hr','payroll']), 403, 'Recipient must be an active workspace user in your school and campus.');
        abort_if($recipient->id === $a->id, 422, 'You already have access to this folder.');

        $grant = (string) Str::uuid();
        DB::transaction(function () use ($grant, $id, $f, $a, $recipient, $p) {
            DB::table('folder_shares')->insert(['id' => $grant, 'permission' => $p['permission'] ?? 'viewer', 'folder_id' => $id, 'created_by' => $a->id, 'recipient_id' => $recipient->id, 'expires_at' => Carbon::parse($p['expires_at'])->utc(), 'created_at' => now()]);
            $this->dms->log($a, 'Folder shared', null, $id.' grant '.$grant);
        });

        return response()->json(['id' => $grant] + $this->shareSheet($a, $f), 201);
    }

    public function revokeFolderShare(Request $r, string $id)
    {
        $a = $this->actor($r);
        $grant = DB::table('folder_shares')->where('id', $id)->where('created_by', $a->id)->first();
        abort_unless($grant, 404);
        $this->folder($grant->folder_id, $a);
        DB::transaction(function () use ($id, $a, $grant) {
            DB::table('folder_shares')->where('id', $id)->update(['revoked_at' => now()]);
            $this->dms->log($a, 'Folder share revoked', null, $grant->folder_id.' grant '.$id);
        });

        return response()->json(['ok' => true]);
    }

    public function shared(Request $r, string $id)
    {
        $a = $this->actor($r);
        $grant = DB::table('document_shares')->where('id', $id)->where('recipient_id', $a->id)->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($grant, 404);
        $this->dms->log($a, 'Share accessed', $grant->document_id, $id);

        return app(ShareController::class)->download($r, $id);
    }
}
