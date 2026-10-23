<?php

namespace App\Http\Controllers;

use App\Http\Requests\HistoryRequest;
use App\Http\Requests\ReadRequest;
use App\Http\Requests\UploadRequest;
use App\Services\Access;
use App\Services\DocumentService;
use App\Services\HistoryService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApiController extends Controller
{
    public function history(HistoryRequest $request, HistoryService $service)
    {
        $input = $request->validated();
        if ($request->isJson()) {
            $input['payload'] = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR)->payload;
        }
        [$body,$status] = $service->receive($request->attributes->get('connector'), $input);

        return response()->json($body, $status);
    }

    public function upload(UploadRequest $request, DocumentService $service, ?string $document = null)
    {
        try {
            [$body,$status] = $service->upload($request->attributes->get('connector'), $request->safe()->except(['file', 'submission_key']), $request->file('file'), $document, $request->validated('submission_key'));

            return response()->json($body, $status);
        } catch (QueryException $e) {
            if (in_array((string) $e->getCode(), ['23000', '40001'])) {
                abort(409, 'Concurrent write conflict. Retry with the same Idempotency-Key.');
            }
            throw $e;
        }
    }

    public function read(ReadRequest $request, string $resource)
    {
        $actor = $request->attributes->get('connector');
        $table = match ($resource) {
            'history' => 'history_receipts','events' => 'integration_events','documents' => 'documents','files' => 'document_versions',default => abort(404)
        };
        $limit = (int) $request->input('limit', 50);
        $after = $request->input('after', 0);
        if ($resource === 'files') {
            $query = DB::table('document_versions as v')->join('documents as d', 'd.id', '=', 'v.document_id');
            Access::visibleTo($query, $actor, 'd.');
            $query->where(function ($q) {
                $q->whereNotIn('d.id', DB::table('memo_publications')->select('document_id'))
                    ->orWhereIn('v.id', DB::table('memo_publications')->select('version_id'));
            });
            $rows = $query->where('v.cursor', '>', $after)->orderBy('v.cursor')->limit($limit + 1)
                ->get(['v.cursor', 'v.id', 'v.document_id', 'v.number', 'v.filename', 'v.mime', 'v.size', 'v.checksum', 'v.scan_status', 'v.created_at']);
            $more = $rows->count() > $limit;
            $rows = $rows->take($limit);

            return response()->json(['data' => $rows->values(), 'next_cursor' => $rows->last()?->cursor ?? (int) $after, 'has_more' => $more]);
        }
        $query = Access::scope(DB::table($table), $actor)->where('source', $actor->source);
        if ($resource === 'documents') {
            // UUIDs are not time ordered. Cursor over creation events for visible
            // documents and hydrate the current document rows.
            $visible = Access::visibleTo(DB::table('documents'), $actor)->select('id');
            $events = Access::scope(DB::table('integration_events'), $actor)->where('source', $actor->source)
                ->whereIn('document_id', $visible)->where('id', '>', $after)->orderBy('id')->limit($limit + 1)->get();
            $more = $events->count() > $limit;
            $events = $events->take($limit);
            $docs = Access::visibleTo(DB::table('documents'), $actor)->whereIn('id', $events->pluck('document_id')->filter())->get();

            return response()->json(['data' => $docs, 'next_cursor' => $events->last()?->id ?? (int) $after, 'has_more' => $more]);
        }
        if ($resource === 'events') {
            $query->whereIn('document_id', Access::visibleTo(DB::table('documents'), $actor)->select('id'));
        }
        foreach (['record_type', 'record_id'] as $field) {
            if ($resource === 'history' && $request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        $rows = $query->where('id', '>', $after)->orderBy('id')->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        foreach ($rows as $row) {
            if (isset($row->payload)) {
                $row->payload = json_decode($row->payload);
            } unset($row->request_hash,$row->scope_key,$row->record_key);
        }

        return response()->json(['data' => $rows->values(), 'next_cursor' => $rows->last()?->id ?? (int) $after, 'has_more' => $more]);
    }

    public function document(Request $request, string $document)
    {
        $actor = $request->attributes->get('connector');
        $doc = Access::visibleTo(DB::table('documents'), $actor)->where('id', $document)->first() ?? abort(404);
        $pin = DB::table('memo_publications')->where('document_id', $document)->value('version_id');
        $versions = DB::table('document_versions')->when($pin, fn ($q) => $q->where('id', $pin))->where('document_id', $document)->orderBy('number')->get(['id', 'number', 'filename', 'mime', 'size', 'checksum', 'scan_status', 'created_at']);

        return response()->json(['data' => $doc, 'versions' => $versions]);
    }

    public function snapshot(Request $request, string $type, string $record)
    {
        $actor = $request->attributes->get('connector');
        $row = Access::scope(DB::table('source_snapshots'), $actor)->where('source', $actor->source)->where('record_type', $type)->where('record_id', $record)->first() ?? abort(404);
        $row->payload = json_decode($row->payload);
        unset($row->record_key);

        return response()->json(['data' => $row]);
    }

    public function download(Request $request, string $version, DocumentService $service)
    {
        return $service->download($request->attributes->get('connector'), $version);
    }
}
