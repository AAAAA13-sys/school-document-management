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

    public function index(Request $r)
    {
        $a = $this->actor($r);
        $p = $r->validate(['folder' => 'nullable|uuid', 'location' => 'nullable|in:home,files,recent,starred,trash', 'search' => 'nullable|string|max:200', 'sort' => 'nullable|in:name,updated', 'page' => 'nullable|integer|min:1']);
        $location = $p['location'] ?? 'files';
        $folder = $p['folder'] ?? null;
        $crumbs = [];
        if ($folder) {
            $f = $this->folder($folder, $a);
            do {
                array_unshift($crumbs, ['id' => $f->id, 'name' => $f->name, 'source' => $f->source]);
                $f = $f->parent_id ? $this->folder($f->parent_id, $a) : null;
            } while ($f);
        }
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
        ($p['sort'] ?? 'updated') === 'name' ? $q->orderBy('title') : $q->orderByDesc('updated_at');
        $items = $q->orderBy('id')->paginate(24);
        $stars = DB::table('document_stars')->where('user_id', $a->id)->pluck('document_id')->all();
        $items->getCollection()->transform(function ($d) use ($stars) {
            $d->starred = in_array($d->id, $stars);
            $d->current = $this->dms->latest($d->id);
            unset($d->current->storage_key);

            return $d;
        });
        $folders = in_array($location, ['home', 'files']) ? $this->dms->scope(DB::table('folders'), $a)->where('parent_id', $folder)->when($p['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.$s.'%'))->orderBy('name')->get() : [];

        return response()->json(['files' => $items, 'folders' => $folders, 'breadcrumbs' => $crumbs]);
    }

    public function folders(Request $r)
    {
        $a = $this->actor($r);

        return response()->json($this->dms->scope(DB::table('folders'), $a)->orderBy('name')->get());
    }

    public function folderDetails(Request $r, string $id)
    {
        $a = $this->actor($r);
        $f = $this->folder($id, $a);
        $activity = DB::table('audit_entries')->where('school_id', $a->school_id)->where('campus', $a->campus)->where('detail', $id)->whereIn('action', ['Folder created','Folder renamed'])->orderByDesc('id')->limit(50)->get(['action','actor','created_at']);
        return response()->json(['folder' => $f, 'activity' => $activity]);
    }

    public function createFolder(Request $r)
    {
        $a = $this->actor($r);
        $p = $r->validate(['name' => 'required|string|max:200', 'source' => 'required|string', 'parent_id' => 'nullable|uuid']);
        abort_unless(in_array($p['source'], $this->dms->sources($a)), 403);
        if (! empty($p['parent_id'])) {
            abort_unless($this->folder($p['parent_id'], $a)->source === $p['source'], 422, 'Folder source must match its parent.');
        }
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $p, $a) {
            DB::table('folders')->insert(['id' => $id, 'owner_user_id' => $a->id, 'name' => $p['name'], 'source' => $p['source'], 'parent_id' => $p['parent_id'] ?? null, 'school_id' => $a->school_id, 'campus' => $a->campus, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $this->dms->log($a, 'Folder created', null, $id);
        });

        return response()->json(['id' => $id], 201);
    }

    public function updateFolder(Request $r, string $id)
    {
        $a = $this->actor($r);
        $p = $r->validate(['name' => 'required|string|max:200', 'revision' => 'required|integer|min:1']);

        return DB::transaction(function () use ($a, $p, $id) {
            $f = $this->folder($id, $a);
            abort_unless(DB::table('folders')->where('id', $id)->where('revision', $p['revision'])->update(['name' => $p['name'], 'revision' => $f->revision + 1, 'updated_at' => now()]), 409, 'Folder changed. Refresh.');
            $this->dms->log($a, 'Folder renamed', null, $id);

            return response()->json(['ok' => true]);
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
        $this->dms->document($v->document_id, $a);

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
        abort_unless($recipient->school_id === $a->school_id && $recipient->campus === $a->campus && in_array($recipient->role, ['student','teacher','employee','admin','registrar','hr','payroll']), 403, 'Recipient must be an active workspace user in your school and campus.');
        $v = $this->dms->latest($id);

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

    public function shared(Request $r, string $id)
    {
        $a = $this->actor($r);
        $grant = DB::table('document_shares')->where('id', $id)->where('recipient_id', $a->id)->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($grant, 404);
        $this->dms->log($a, 'Share accessed', $grant->document_id, $id);

        return app(ShareController::class)->download($r, $id);
    }
}
