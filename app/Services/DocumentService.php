<?php

namespace App\Services;

use App\Jobs\ScanDocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentService
{
    public function upload(object $actor, array $input, UploadedFile $file, ?string $id = null, ?string $requestKey = null): array
    {
        $checksum = hash_file('sha256', $file->getRealPath());
        $scope = HistoryService::hash([$actor->school_id, $actor->campus, $actor->source, $actor instanceof User ? 'user:'.$actor->id : 'connector']);
        $hash = HistoryService::hash([$input, $checksum, $file->getClientOriginalName(), $id]);
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $input, $file, $id, $requestKey, $scope, $hash, $checksum, &$stored) {
                if ($actor instanceof User) {
                    DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
                } else {
                    DB::table('connectors')->where('school_id', $actor->school_id)->where('campus', $actor->campus)->where('source', $actor->source)->orderBy('id')->lockForUpdate()->get();
                }
                if (! ($actor instanceof User)) {
                    abort_unless(DB::table('connectors')->where('id', $actor->id)->where('active', true)->exists(), 401);
                }
                if ($requestKey) {
                    $prior = DB::table('request_receipts')->where('scope_key', $scope)->where('request_key', $requestKey)->lockForUpdate()->first();
                    if ($prior) {
                        abort_unless(hash_equals($prior->request_hash, $hash), 409, 'Submission key content mismatch.');

                        return [json_decode($prior->response, true), 200];
                    }
                }
                $source = $actor instanceof User ? $input['source'] : $actor->source;
                if ($id) {
                    $doc = DB::table('documents')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
                    $this->authorize($actor, $doc, true);
                    abort_if($doc->trashed_at, 409, 'Restore this document before adding a version.');
                    abort_unless($doc->revision === (int) $input['revision'], 409, 'Document changed. Reload and retry.');
                    DB::table('documents')->where('id', $id)->update(['revision' => $doc->revision + 1, 'updated_at' => now()]);
                } else {
                    if ($actor instanceof User) {
                        abort_unless($actor->role === 'super_admin' ? $source === 'DMS' : in_array($source, $actor->sources()), 403);
                    }
                    if (! empty($input['folder_id'])) {
                        $folder = Access::scope(DB::table('folders'), $actor)->where('id', $input['folder_id'])->where('source', $source)->first();
                        abort_unless($folder && $actor instanceof User && ($folder->owner_id === $actor->id || Access::manages($actor, $folder)), 403);
                    }
                    $id = (string) Str::uuid();
                    DB::table('documents')->insert(['id' => $id, 'owner_id' => $actor instanceof User ? $actor->id : null,
                        'folder_id' => $input['folder_id'] ?? null, 'source' => $source, 'school_id' => $actor->school_id, 'campus' => $actor->campus,
                        'title' => $input['title'], 'subject' => $input['subject'] ?? null, 'reference' => $input['reference'] ?? null,
                        'category' => $input['category'] ?? null, 'classification' => $input['classification'], 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
                $version = (string) Str::uuid();
                $stored = 'documents/'.$version;
                abort_unless(Storage::disk('local')->putFileAs('documents', $file, $version), 500, 'Could not store the file.');
                $status = 'Pending';
                $number = (int) DB::table('document_versions')->where('document_id', $id)->max('number') + 1;
                DB::table('document_versions')->insert(['id' => $version, 'document_id' => $id, 'number' => $number,
                    'filename' => basename(str_replace('\\', '/', $file->getClientOriginalName())), 'mime' => $file->getMimeType(),
                    'size' => $file->getSize(), 'checksum' => $checksum, 'storage_key' => $stored, 'scan_status' => $status, 'created_at' => now()]);
                $doc = DB::table('documents')->where('id', $id)->first();
                Access::audit($actor, 'document.uploaded', $doc, ['version_id' => $version, 'scan_status' => $status]);
                Access::event($doc, 'document.version.created');
                $response = ['document_id' => $id, 'version_id' => $version, 'revision' => $doc->revision, 'scan_status' => $status];
                if ($requestKey) {
                    DB::table('request_receipts')->insert(['scope_key' => $scope, 'request_key' => $requestKey, 'request_hash' => $hash, 'response' => json_encode($response), 'created_at' => now()]);
                }
                ScanDocumentVersion::dispatch($version)->onConnection('database')->onQueue('scans')->afterCommit();

                return [$response, 201];
            });
        } catch (\Throwable $e) {
            if ($stored && ! DB::table('document_versions')->where('storage_key', $stored)->exists()) {
                Storage::disk('local')->delete($stored);
            }
            throw $e;
        }
    }

    public function authorize(object $actor, object $doc, bool $edit = false): void
    {
        abort_unless($actor->school_id === $doc->school_id && $actor->campus === $doc->campus, 404);
        if ($actor instanceof User) {
            Access::document($actor, $doc->id);
            if ($edit) {
                Access::edit($actor, $doc);
            }
        } else {
            abort_unless($actor->source === $doc->source, 404);
        }
    }

    public function download(object $actor, string $versionId, bool $preview = false)
    {
        $version = DB::table('document_versions')->where('id', $versionId)->first() ?? abort(404);
        $doc = DB::table('documents')->where('id', $version->document_id)->first() ?? abort(404);
        $this->authorize($actor, $doc);
        abort_if($doc->trashed_at, 404);
        if ($actor instanceof User && $doc->owner_id !== $actor->id && ! ($actor->role === 'super_admin' || ($actor->role !== 'staff' && in_array($doc->source, $actor->sources())))) {
            $grant = Access::grant($actor, $doc);
            abort_if($grant?->version_id && $grant->version_id !== $versionId, 403);
        }
        abort_unless($version->scan_status === 'Clean', 423, 'File is blocked until malware scanning succeeds.');
        abort_unless(Storage::disk('local')->exists($version->storage_key), 409, 'Stored file is unavailable.');
        $path = Storage::disk('local')->path($version->storage_key);
        abort_unless(hash_equals($version->checksum, hash_file('sha256', $path)), 409, 'Checksum mismatch. File blocked.');
        Access::audit($actor, $preview ? 'document.previewed' : 'document.downloaded', $doc, ['version_id' => $versionId]);
        $headers = ['Content-Type' => $version->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "sandbox; default-src 'none'"];
        if ($preview) {
            abort_unless(in_array($version->mime, config('dms.preview_mimes')) && in_array($version->mime, ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain', 'text/csv', 'application/json']), 415, 'This file format has no in-app preview. Use download.');

            if (in_array($version->mime, ['text/plain','text/csv','application/json'])) {
                $headers['Content-Type'] = 'text/html; charset=UTF-8';
                $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; style-src 'unsafe-inline'";
                return response()->view('partials.file-text-preview', ['filename'=>$version->filename,'content'=>file_get_contents($path)], 200, $headers);
            }
            // Native PDF viewers require a document response without the sandbox directive.
            if ($version->mime === 'application/pdf') $headers['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'self'";
            return response()->file($path, $headers);
        }

        return response()->download($path, $version->filename, $headers);
    }
}
