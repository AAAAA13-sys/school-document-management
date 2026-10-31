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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

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
            $nextCursor = $more ? ($rows->last()?->cursor ?? (int) $after) : null;

            return response()->json([
                'data' => $rows->values(),
                'meta' => [
                    'next_cursor' => $nextCursor,
                    'has_more' => $more,
                ],
                'next_cursor' => $nextCursor,
                'has_more' => $more,
            ]);
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
            $nextCursor = $more ? ($events->last()?->id ?? (int) $after) : null;

            return response()->json([
                'data' => $docs,
                'meta' => [
                    'next_cursor' => $nextCursor,
                    'has_more' => $more,
                ],
                'next_cursor' => $nextCursor,
                'has_more' => $more,
            ]);
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
        $nextCursor = $more ? ($rows->last()?->id ?? (int) $after) : null;

        return response()->json([
            'data' => $rows->values(),
            'meta' => [
                'next_cursor' => $nextCursor,
                'has_more' => $more,
            ],
            'next_cursor' => $nextCursor,
            'has_more' => $more,
        ]);
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

    public function downloadUrl(Request $request, string $document)
    {
        $actor = $request->attributes->get('connector');
        $doc = Access::visibleTo(DB::table('documents'), $actor)->where('id', $document)->first() ?? abort(404);
        $latest = DB::table('document_versions')->where('document_id', $document)->orderByDesc('number')->first() ?? abort(404);

        $url = URL::temporarySignedRoute('api.files.stream', now()->addMinutes(10), ['version' => $latest->id]);

        return response()->json(['url' => $url]);
    }

    public function streamSigned(Request $request, string $version)
    {
        abort_unless($request->hasValidSignature(), 401, 'Invalid or expired download link.');
        $row = DB::table('document_versions')->where('id', $version)->first() ?? abort(404);

        abort_unless($row->scan_status === 'Clean', 423, 'File is blocked until malware scanning succeeds.');
        $path = Storage::disk('local')->path($row->storage_key);
        abort_unless(file_exists($path), 404, 'File not found on disk.');
        abort_unless(hash_equals($row->checksum, hash_file('sha256', $path)), 409, 'Checksum mismatch.');

        return response()->file($path, [
            'Content-Type' => $row->mime,
            'Content-Disposition' => 'inline; filename="'.rawurlencode($row->filename).'"',
        ]);
    }

    public function syncApplicant(Request $request, string $ref, HistoryService $historyService)
    {
        $actor = $request->attributes->get('connector');
        abort_unless($actor->source === 'OAS', 403, 'Applicant sync is restricted to OAS.');

        $idempotencyKey = $request->header('Idempotency-Key', (string) Str::uuid());
        $payload = $request->all();

        $input = [
            'event_id' => $idempotencyKey,
            'record_key' => 'oas:applicant:'.$ref,
            'record_type' => 'applicant',
            'record_id' => $ref,
            'revision' => (int) ($payload['revision'] ?? 1),
            'operation' => 'upsert',
            'payload' => $payload,
        ];

        [$body, $status] = $historyService->receive($actor, $input);

        return response()->json(['applied' => true, 'id' => $ref, 'applicant_ref' => $ref] + $body, $status);
    }

    public function syncApplicantEvent(Request $request, string $ref, HistoryService $historyService)
    {
        $actor = $request->attributes->get('connector');
        abort_unless($actor->source === 'OAS', 403, 'Applicant events are restricted to OAS.');

        $idempotencyKey = $request->header('Idempotency-Key', (string) Str::uuid());
        $payload = $request->all();

        $input = [
            'event_id' => $idempotencyKey,
            'record_key' => 'oas:event:'.$idempotencyKey,
            'record_type' => 'applicant_event',
            'record_id' => $idempotencyKey,
            'revision' => (int) ($payload['revision'] ?? 1),
            'operation' => 'upsert',
            'payload' => ['applicant_ref' => $ref] + $payload,
        ];

        [$body, $status] = $historyService->receive($actor, $input);

        return response()->json(['applied' => true, 'event_id' => $idempotencyKey, 'applicant_ref' => $ref] + $body, $status);
    }
}
