<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ShareController extends Controller
{
    public function __construct(private DocumentService $dms) {}

    private function grant(Request $r, string $id): array
    {
        $a = $this->dms->actor($r);
        abort_unless(in_array($a->role, ['student','teacher','employee','admin','registrar','hr','payroll']), 403);
        $g = DB::table('document_shares')->where('id', $id)->where('recipient_id', $a->id)->whereNull('revoked_at')->where('expires_at', '>', now())->lockForUpdate()->first();
        abort_unless($g, 404);
        $d = DB::table('documents')->where('id', $g->document_id)->where('school_id', $a->school_id)->where('campus', $a->campus)->whereNull('trashed_at')->lockForUpdate()->first();
        $sender = User::find($g->created_by);
        abort_unless($d && $sender && $sender->active && $this->dms->scope(DB::table('documents'), $sender)->where('id', $d->id)->exists(), 404);

        return [$a, $g, $d];
    }

    public function index(Request $r)
    {
        $a = $this->dms->actor($r);
        $p = $r->validate(['page' => 'nullable|integer|min:1', 'search' => 'nullable|string|max:200']);
        $rows = DB::table('document_shares')->join('documents', 'documents.id', '=', 'document_shares.document_id')
            ->join('users', 'users.id', '=', 'document_shares.created_by')->where('recipient_id', $a->id)
            ->where('documents.school_id', $a->school_id)->where('documents.campus', $a->campus)
            ->where('users.active', true)->whereNull('documents.trashed_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->when($p['search'] ?? null, fn ($q, $s) => $q->where('documents.title', 'like', '%'.$s.'%'))
            ->orderByDesc('document_shares.created_at')->paginate(24, ['documents.*', 'document_shares.id as grant_id', 'version_id', 'permission', 'users.name as shared_by']);
        $rows->getCollection()->transform(function ($d) use ($r) {
            try { $this->grant($r, $d->grant_id); } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) { if ($e->getStatusCode() !== 404) throw $e; return null; }
            $d->current = DB::table('document_versions')->where('id', $d->version_id)->first();
            unset($d->current->storage_key);
            return $d;
        });
        $rows->setCollection($rows->getCollection()->filter()->values());
        return response()->json(['files' => $rows, 'folders' => [], 'breadcrumbs' => []]);
    }

    public function download(Request $r, string $id)
    {
        [$a, $g, $d] = $this->grant($r, $id);
        $v = DB::table('document_versions')->where('id', $g->version_id)->where('document_id', $d->id)->first();
        abort_unless($v && $v->scan === 'Clean', 403, 'Security checks must pass first.');
        abort_unless(Storage::disk('local')->exists($v->storage_key), 503);
        abort_unless(hash_equals($v->checksum, hash_file('sha256', Storage::disk('local')->path($v->storage_key))), 409, 'File integrity check failed.');
        $this->dms->log($a, 'Shared document downloaded', $d->id, $id);
        return Storage::disk('local')->download($v->storage_key, $v->filename, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function comments(Request $r, string $id)
    {
        [$a, $g, $d] = $this->grant($r, $id);
        return response()->json(DB::table('document_comments')->join('users', 'users.id', '=', 'document_comments.user_id')->where('document_id', $d->id)->orderByDesc('document_comments.id')->limit(100)->get(['body', 'users.name', 'document_comments.created_at']));
    }

    public function comment(Request $r, string $id)
    {
        return DB::transaction(fn () => $this->commentLocked($r, $id));
    }

    private function commentLocked(Request $r, string $id)
    {
        [$a, $g, $d] = $this->grant($r, $id);
        abort_unless(in_array($g->permission, ['commenter', 'editor']), 403);
        $p = $r->validate(['body' => 'required|string|max:2000']);
        DB::transaction(function () use ($a, $d, $p) {
            DB::table('document_comments')->insert(['document_id' => $d->id, 'user_id' => $a->id, 'body' => $p['body'], 'created_at' => now()]);
            $this->dms->log($a, 'Document comment added', $d->id);
        });
        return response()->json(['ok' => true], 201);
    }

    public function edit(Request $r, string $id)
    {
        return DB::transaction(fn () => $this->editLocked($r, $id));
    }

    private function editLocked(Request $r, string $id)
    {
        [$a, $g, $d] = $this->grant($r, $id);
        abort_unless($g->permission === 'editor', 403);
        $p = $r->validate(['title' => 'required|string|max:200', 'revision' => 'required|integer|min:1']);
        DB::transaction(function () use ($a, $d, $p) {
            abort_unless(DB::table('documents')->where('id', $d->id)->whereNull('trashed_at')->where('revision', $p['revision'])->update(['title' => $p['title'], 'revision' => $d->revision + 1, 'updated_at' => now()]), 409, 'Document changed. Refresh and retry.');
            $d->revision++;
            $this->dms->event($d, 'document.rename', $this->dms->latest($d->id)->id);
            $this->dms->log($a, 'Shared document renamed', $d->id);
        });
        return response()->json(['ok' => true]);
    }

    public function replace(Request $r, string $id)
    {
        return DB::transaction(fn () => $this->replaceLocked($r, $id));
    }

    private function replaceLocked(Request $r, string $id)
    {
        [$a, $g, $d] = $this->grant($r, $id);
        abort_unless($g->permission === 'editor', 403);
        $r->merge(array_intersect_key((array) $d, array_flip(['title', 'subject', 'reference', 'source', 'category', 'classification'])) + ['document_id' => $d->id]);
        return response()->json($this->dms->upload($r, $a, true), 201);
    }
}
