<?php

namespace App\Http\Controllers;

use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    public function __construct(private DocumentService $dms) {}

    public function index(Request $r)
    {
        $a = $this->dms->actor($r);
        $r->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100', 'search' => 'nullable|string|max:200', 'source' => 'nullable|string|max:100', 'status' => 'nullable|string|max:40']);
        $q = $this->dms->scope(DB::table('documents'), $a)->whereNull('trashed_at');
        if ($s = $r->input('search')) {
            $q->where(fn ($x) => $x->where('title', 'like', '%'.$s.'%')->orWhere('subject', 'like', '%'.$s.'%')->orWhere('reference', 'like', '%'.$s.'%'));
        }
        if ($s = $r->input('source')) {
            $q->where('source', $s);
        }
        if ($s = $r->input('status')) {
            abort_unless(in_array($s, ['Available', 'Awaiting scan']), 422, 'Filter by file availability, not business approval.');
            $q->whereExists(fn ($x) => $x->selectRaw('1')->from('document_versions as v')->whereColumn('v.document_id', 'documents.id')->where('v.scan', $s === 'Available' ? '=' : '!=', 'Clean')->whereRaw('v.number=(SELECT MAX(v2.number) FROM document_versions v2 WHERE v2.document_id=documents.id)'));
        }
        $items = $q->orderByDesc('updated_at')->orderBy('id')->paginate((int) $r->input('per_page', 50));
        $items->getCollection()->transform(function ($d) {
            $d->current = $this->dms->latest($d->id);
            $d->availability = $d->current->scan === 'Clean' ? 'Available' : 'Awaiting scan';
            unset($d->current->storage_key);

            return $d;
        });

        return response()->json($items);
    }

    public function show(Request $r, string $id)
    {
        $a = $this->dms->actor($r);
        $d = $this->dms->document($id, $a);
        $v = DB::table('document_versions')->where('document_id', $id)->orderByDesc('number')->get();
        foreach ($v as $x) {
            unset($x->storage_key);
        }$this->dms->log($a, 'Document viewed', $id);

        $comments = DB::table('document_comments')->join('users', 'users.id', '=', 'document_comments.user_id')->where('document_id', $d->id)->orderByDesc('document_comments.id')->limit(100)->get(['body', 'users.name', 'document_comments.created_at']);
        $activity = DB::table('audit_entries')->where('document_id', $d->id)->where('school_id', $a->school_id)->where('campus', $a->campus)->orderByDesc('id')->limit(50)->get(['action','actor','created_at']);
        return response()->json(['document' => $d, 'versions' => $v, 'comments' => $comments, 'activity' => $activity]);
    }

    public function store(Request $r)
    {
        return response()->json($this->dms->upload($r, $this->dms->actor($r)), 201);
    }

    public function stats(Request $r)
    {
        $a = $this->dms->actor($r);
        $docs = $this->dms->scope(DB::table('documents'), $a)->whereNull('trashed_at')->get();
        $statuses = $docs->map(fn ($d) => $this->dms->latest($d->id)->scan);

        return response()->json(['total' => $docs->count(), 'available' => $statuses->filter(fn ($s) => $s === 'Clean')->count(), 'scan' => $statuses->filter(fn ($s) => $s !== 'Clean')->count(), 'sources' => collect($this->dms->sources($a))->map(fn ($s) => ['source' => $s, 'count' => $docs->where('source', $s)->count()])]);
    }

    public function download(Request $r, string $id)
    {
        $a = $this->dms->actor($r);
        $v = DB::table('document_versions')->where('id', $id)->first();
        abort_unless($v, 404);
        $this->dms->document($v->document_id, $a);
        abort_unless($v->scan === 'Clean', 403, 'Download blocked until scan passes.');
        abort_unless(Storage::disk('local')->exists($v->storage_key), 503, 'File unavailable.');
        abort_unless(hash_equals($v->checksum, hash_file('sha256', Storage::disk('local')->path($v->storage_key))), 409, 'File integrity check failed.');
        $this->dms->log($a, 'Document downloaded', $v->document_id, 'Version '.$v->number);

        return Storage::disk('local')->download($v->storage_key, 'document-v'.$v->number.'.'.pathinfo($v->filename, PATHINFO_EXTENSION), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function audit(Request $r)
    {
        $a = $this->dms->actor($r);
        abort_unless(($a->role ?? null) === 'admin', 403);

        return response()->json(['items' => DB::table('audit_entries')->where('school_id', $a->school_id)->where('campus', $a->campus)->orderByDesc('id')->limit(250)->get()]);
    }

    public function events(Request $r)
    {
        $a = $this->dms->actor($r);
        $p = $r->validate(['after' => 'nullable|integer|min:0']);
        $rows = $this->dms->scope(DB::table('integration_events'), $a)->where('id', '>', $p['after'] ?? 0)->orderBy('id')->limit(100)->get();

        return response()->json(['schema_version' => 1, 'items' => $rows, 'next_cursor' => $rows->last()?->id ?? ($p['after'] ?? 0)]);
    }
}
