<?php

namespace App\Http\Controllers;

use App\Services\DocumentService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HistoryController extends Controller
{
    public function __construct(private DocumentService $documents) {}

    private function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = $this->canonical($item);
            }
        }

        return $value;
    }

    public function store(Request $request)
    {
        $actor = $this->documents->actor($request);
        abort_unless(isset($actor->source), 403);
        abort_unless(in_array($actor->source, config('dms.systems'), true), 403, 'Unknown source system.');
        abort_if(strlen($request->getContent()) > 262144, 413, 'Event exceeds 256 KiB.');
        $data = $request->validate([
            'event_id' => 'required|uuid',
            'record_type' => 'required|string|max:100|regex:/^[a-zA-Z0-9_.-]+$/',
            'record_id' => 'required|string|max:200',
            'revision' => 'required|integer|min:1|max:2147483647',
            'operation' => 'required|in:upsert,delete',
            'occurred_at' => 'required|date',
            'payload' => 'present|array',
        ]);
        abort_if($data['operation'] === 'delete' && $data['payload'] !== [], 422, 'Deletion events must have an empty payload.');
        $hash = hash('sha256', json_encode($this->canonical($data), JSON_THROW_ON_ERROR));
        $key = hash('sha256', json_encode([$actor->school_id, $actor->campus, $actor->source, $data['record_type'], $data['record_id']], JSON_THROW_ON_ERROR));
        try {
            $result = DB::transaction(function () use ($actor, $data, $hash, $key) {
                // Creating the slot also serializes competing writers on this record.
                DB::table('source_records')->insertOrIgnore([
                    'id' => $key, 'school_id' => $actor->school_id, 'campus' => $actor->campus,
                    'source' => $actor->source, 'record_type' => $data['record_type'], 'record_id' => $data['record_id'],
                    'revision' => 0, 'deleted' => false, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $record = DB::table('source_records')->where('id', $key)->lockForUpdate()->first();
                $prior = DB::table('source_history')->where('integration_account_id', $actor->id)->where('event_id', $data['event_id'])->first();
                if ($prior) {
                    abort_unless(hash_equals($prior->request_hash, $hash), 409, 'Event ID reused with different content.');

                    return ['history_id' => $prior->id, 'applied' => (bool) $prior->applied, 'duplicate' => true];
                }
                abort_if(DB::table('source_history')->where('record_key', $key)->where('revision', $data['revision'])->exists(), 409, 'Record revision already received with another event ID.');
                $applied = $data['revision'] > $record->revision;
                $payload = json_encode($this->canonical($data['payload']), JSON_THROW_ON_ERROR);
                if ($applied) {
                    $changed = DB::table('source_records')->where('id', $key)->where('revision', $record->revision)->update([
                        'revision' => $data['revision'], 'deleted' => $data['operation'] === 'delete',
                        'payload' => $payload, 'updated_at' => now(),
                    ]);
                    abort_unless($changed === 1, 409, 'Concurrent update; retry this same event.');
                }
                $id = DB::table('source_history')->insertGetId([
                    'integration_account_id' => $actor->id, 'record_key' => $key,
                    'event_id' => $data['event_id'], 'revision' => $data['revision'], 'operation' => $data['operation'],
                    'request_hash' => $hash, 'payload' => $payload,
                    'occurred_at' => CarbonImmutable::parse($data['occurred_at'])->utc(), 'received_at' => now(), 'applied' => $applied,
                ]);
                $this->documents->log($actor, 'Source history received', null, 'History '.$id.'; revision '.$data['revision']);

                return ['history_id' => $id, 'applied' => $applied, 'duplicate' => false];
            }, 3);
        } catch (QueryException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505', '40001']) ||
                ((string) $exception->getCode() === 'HY000' && in_array($exception->errorInfo[1] ?? null, [5, 6, 1205, 1213], true))) {
                abort(409, 'Concurrent ingestion conflict; retry the same event ID.');
            }
            throw $exception;
        }

        return response()->json($result, $result['duplicate'] ? 200 : 201);
    }

    public function index(Request $request)
    {
        $actor = $this->documents->actor($request);
        $data = $request->validate(['after' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:100', 'source' => 'nullable|string|max:200', 'record_id' => 'nullable|string|max:200']);
        $rows = $this->documents->scope(DB::table('source_history')->join('source_records', 'source_records.id', '=', 'source_history.record_key'), $actor)
            ->when($data['source'] ?? null, fn ($q, $source) => $q->where('source_records.source', $source))
            ->when($data['record_id'] ?? null, fn ($q, $record) => $q->where('source_records.record_id', $record))
            ->where('source_history.id', '>', $data['after'] ?? 0)->orderBy('source_history.id')->limit($data['limit'] ?? 50)
            ->get(['source_history.*', 'source_records.source', 'source_records.record_type', 'source_records.record_id']);
        foreach ($rows as $row) {
            $row->payload = json_decode($row->payload, true);
            unset($row->request_hash, $row->integration_account_id, $row->record_key);
        }
        $this->documents->log($actor, 'Source history read');

        return response()->json(['data' => $rows, 'next_cursor' => $rows->last()->id ?? ($data['after'] ?? 0)]);
    }

    public function show(Request $request, int $id)
    {
        $actor = $this->documents->actor($request);
        $entry = $this->documents->scope(DB::table('source_history')->join('source_records', 'source_records.id', '=', 'source_history.record_key'), $actor)
            ->where('source_history.id', $id)->first(['source_history.*', 'source_records.source', 'source_records.record_type', 'source_records.record_id',
                'source_records.revision as current_revision', 'source_records.deleted as currently_deleted', 'source_records.payload as current_payload']);
        abort_unless($entry, 404);
        $entry->payload = json_decode($entry->payload, true);
        $entry->current_payload = json_decode($entry->current_payload, true);
        unset($entry->request_hash, $entry->integration_account_id, $entry->record_key);
        $this->documents->log($actor, 'Source history detail read', null, 'History '.$id);

        return response()->json($entry);
    }
}
