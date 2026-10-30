<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** FR-12: OAS assertions are source evidence; DMS never verifies admissions. */
class ApplicantPacketService
{
    public function receive(object $connector, array $p): array
    {
        abort_unless($connector->source === 'OAS', 403);

        return DB::transaction(function () use ($connector, $p) {
            Access::scope(DB::table('connectors'), $connector)->where('source', 'OAS')->orderBy('id')->lockForUpdate()->get();
            $fresh = DB::table('connectors')->where('id', $connector->id)->lockForUpdate()->first();
            abort_unless($fresh && $fresh->active && $fresh->source === 'OAS' && $fresh->school_id === $connector->school_id && $fresh->campus === $connector->campus, 401);
            $scope = HistoryService::hash([$connector->school_id, $connector->campus, 'OAS']);
            $hash = HistoryService::hash($p);
            $prior = DB::table('packet_receipts')->where('scope_key', $scope)->where('event_id', $p['event_id'])->first();
            if ($prior) {
                abort_unless(hash_equals($prior->request_hash, $hash), 409);

                return [json_decode($prior->response, true), 200];
            }
            abort_if(DB::table('packet_receipts')->where('scope_key', $scope)->where('applicant_id', $p['applicant_id'])->where('revision', $p['revision'])->exists(), 409, 'Packet revision already received.');
            $query = Access::scope(DB::table('applicant_packets'), $connector)->where('applicant_id', $p['applicant_id']);
            $packet = (clone $query)->lockForUpdate()->first();
            abort_if($packet && (int) $packet->revision === (int) $p['revision'], 409, 'Packet revision already exists.');
            $applied = ! $packet || (int) $p['revision'] > (int) $packet->revision;
            if ($applied) {
                $versions = array_values(array_unique($p['version_ids'] ?? []));
                if ($p['released']) {
                    abort_unless(($p['admitted'] ?? false) && ($p['verified'] ?? false) && count($versions), 422, 'Release requires an admitted applicant and verified versions.');
                    foreach ($versions as $version) {
                        $this->allowedVersion($connector, $version);
                    }
                }
                $id = $packet?->id ?? (string) Str::uuid();
                $values = ['revision' => $p['revision'], 'released' => $p['released'], 'versions' => json_encode($p['released'] ? $versions : []), 'updated_at' => now()];
                if ($packet) {
                    $query->update($values);
                } else {
                    DB::table('applicant_packets')->insert($values + ['id' => $id, 'school_id' => $connector->school_id, 'campus' => $connector->campus, 'applicant_id' => $p['applicant_id'], 'created_at' => now()]);
                }
            } else {
                $id = $packet->id;
            }
            $body = ['packet_id' => $id, 'revision' => (int) $p['revision'], 'applied' => $applied];
            // Normalize JSON key order for database engines that canonicalize JSON columns.
            ksort($body);
            DB::table('packet_receipts')->insert(['scope_key' => $scope, 'event_id' => $p['event_id'], 'applicant_id' => $p['applicant_id'], 'revision' => $p['revision'], 'request_hash' => $hash, 'payload' => json_encode($p), 'response' => json_encode($body), 'created_at' => now()]);
            Access::audit($connector, 'packet.received', null, $body + ['released' => $p['released']]);

            return [$body, 201];
        }, 3);
    }

    public function read(object $connector, string $id): array
    {
        abort_unless($connector->source === 'ES' && DB::table('connectors')->where('id', $connector->id)->where('active', true)->where('source', 'ES')->where('school_id', $connector->school_id)->where('campus', $connector->campus)->exists(), 403);
        $packet = Access::scope(DB::table('applicant_packets'), $connector)->where('id', $id)->where('released', true)->first() ?? abort(404);
        $versions = [];
        foreach (json_decode($packet->versions, true) as $id) {
            $v = $this->allowedVersion($connector, $id);
            $versions[] = ['document_id' => $v->document_id, 'version_id' => $v->id, 'filename' => $v->filename, 'checksum' => $v->checksum];
        }
        Access::audit($connector, 'packet.read', null, ['packet_id' => $packet->id]);

        return ['packet_id' => $packet->id, 'applicant_id' => $packet->applicant_id, 'revision' => $packet->revision, 'versions' => $versions];
    }

    public function download(object $connector, string $packet, string $version)
    {
        return DB::transaction(function () use ($connector, $packet, $version) {
            // Hold release state until the response is prepared; every request rechecks it.
            DB::table('applicant_packets')->where('id', $packet)->lockForUpdate()->first();
            $body = $this->read($connector, $packet);
            abort_unless(in_array($version, array_column($body['versions'], 'version_id'), true), 404);
            $v = $this->allowedVersion($connector, $version);
            abort_unless(Storage::disk('local')->exists($v->storage_key), 409);
            $path = Storage::disk('local')->path($v->storage_key);
            abort_unless(hash_equals($v->checksum, hash_file('sha256', $path)), 409, 'Checksum mismatch.');
            Access::audit($connector, 'packet.file.downloaded', null, ['packet_id' => $packet, 'version_id' => $version]);

            return response()->download($path, $v->filename, ['Content-Type' => $v->mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
        });
    }

    private function allowedVersion(object $actor, string $version): object
    {
        $v = DB::table('document_versions')->where('id', $version)->where('scan_status', 'Clean')->first() ?? abort(423, 'Released versions must remain Clean.');
        $doc = Access::scope(DB::table('documents'), $actor)->where('id', $v->document_id)->where('source', 'OAS')->whereNull('trashed_at')->whereIn('classification', ['Internal', 'Confidential'])->first() ?? abort(404);
        app(ReleaseGrant::class)->authorize($doc);

        return $v;
    }
}
