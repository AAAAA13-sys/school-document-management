<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class HistoryService
{
    public static function canonical(mixed $value): mixed
    {
        if (is_object($value)) {
            $fields = get_object_vars($value);
            ksort($fields, SORT_STRING);

            return (object) array_map(self::canonical(...), $fields);
        }
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonical(...), $value);
    }

    public static function hash(array $data): string
    {
        return hash('sha256', json_encode(self::canonical($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function receive(object $actor, array $input): array
    {
        $scope = self::hash([$actor->school_id, $actor->campus, $actor->source]);
        $key = self::hash([$scope, $input['record_type'], $input['record_id']]);
        $hash = self::hash($input);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return DB::transaction(function () use ($actor, $input, $scope, $key, $hash) {
                    // Lock one source scope before the record to serialize receipt creation and revisions.
                    DB::table('connectors')->where('school_id', $actor->school_id)->where('campus', $actor->campus)
                        ->where('source', $actor->source)->orderBy('id')->lockForUpdate()->get();
                    abort_unless(DB::table('connectors')->where('id', $actor->id)->where('active', true)->exists(), 401);
                    $prior = DB::table('history_receipts')->where('scope_key', $scope)->where('event_id', $input['event_id'])->first();
                    if ($prior) {
                        abort_unless(hash_equals($prior->request_hash, $hash), 409, 'Event ID was already used with different content.');

                        return [['history_id' => $prior->id, 'applied' => (bool) $prior->applied, 'duplicate' => true], 200];
                    }
                    $snapshot = DB::table('source_snapshots')->where('record_key', $key)->lockForUpdate()->first();
                    abort_if(DB::table('history_receipts')->where('record_key', $key)->where('revision', $input['revision'])->exists(), 409, 'This record revision already has an event.');
                    $applied = ! $snapshot || $input['revision'] > $snapshot->revision;
                    $scopeFields = ['source' => $actor->source, 'school_id' => $actor->school_id, 'campus' => $actor->campus];
                    $id = DB::table('history_receipts')->insertGetId($scopeFields + [
                        'scope_key' => $scope, 'record_key' => $key, 'event_id' => $input['event_id'], 'request_hash' => $hash,
                        'record_type' => $input['record_type'], 'record_id' => $input['record_id'], 'revision' => $input['revision'],
                        'operation' => $input['operation'], 'payload' => json_encode($input['payload']), 'applied' => $applied, 'created_at' => now(),
                    ]);
                    if ($applied) {
                        $values = $scopeFields + ['record_type' => $input['record_type'], 'record_id' => $input['record_id'],
                            'revision' => $input['revision'], 'deleted' => $input['operation'] === 'delete',
                            'payload' => json_encode($input['payload']), 'updated_at' => now()];
                        if ($snapshot) {
                            DB::table('source_snapshots')->where('id', $snapshot->id)->update($values);
                        } else {
                            DB::table('source_snapshots')->insert($values + ['record_key' => $key, 'created_at' => now()]);
                        }
                    }
                    Access::audit($actor, 'history.received', details: ['history_id' => $id, 'applied' => $applied]);

                    return [['history_id' => $id, 'applied' => $applied, 'duplicate' => false], 201];
                }, 3);
            } catch (QueryException $e) {
                if (! ((string) $e->getCode() === '40001' || in_array((int) ($e->errorInfo[1] ?? 0), [1062, 1205, 1213])) || $attempt === 2) {
                    if ((string) $e->getCode() === '40001' || in_array((int) ($e->errorInfo[1] ?? 0), [1062, 1205, 1213])) {
                        abort(409, 'Concurrent write conflict. Retry the same event.');
                    }
                    throw $e;
                }
            }
        }
        abort(409);
    }
}
