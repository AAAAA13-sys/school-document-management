<?php

namespace App\Services;

use App\Exceptions\DuplicateFileException;
use App\Jobs\ScanDocumentVersion;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentService
{
    public function listingVersions(User $user, LengthAwarePaginator $documents): Collection
    {
        $documents = $documents->getCollection();
        $versions = DB::table('document_versions')
            ->whereIn('document_id', $documents->pluck('id'))
            ->orderBy('number')
            ->get()
            ->keyBy('document_id');

        foreach ($documents as $document) {
            $memoPin = MemoService::circulatedVersion($user, $document);
            if ($memoPin) {
                $versions[$document->id] = DB::table('document_versions')->where('id', $memoPin)->first();

                continue;
            }
            if ($document->owner_id === $user->id || Gate::forUser($user)->allows('document.all-versions', $document)) {
                continue;
            }
            $grant = Access::grant($user, $document);
            if ($grant?->version_id) {
                $versions[$document->id] = DB::table('document_versions')->where('id', $grant->version_id)->first();
            }
        }

        return $versions;
    }

    public function upload(object $actor, array $input, UploadedFile $file, ?string $id = null, ?string $requestKey = null): array
    {
        // The duplicate confirmation is a client decision, not document content: keep it
        // out of the idempotency hash so the same submission key stays replayable.
        $confirmDuplicate = (bool) ($input['confirm_duplicate'] ?? false);
        unset($input['confirm_duplicate']);
        $forceNewVersion = filter_var($input['force_new_version'] ?? request()->input('force_new_version', false), FILTER_VALIDATE_BOOLEAN);
        unset($input['force_new_version']);
        $checksum = hash_file('sha256', $file->getRealPath());
        $scope = HistoryService::hash([$actor->school_id, $actor->campus, $actor->source, $actor instanceof User ? 'user:'.$actor->id : 'connector']);
        $hash = HistoryService::hash([$input, $checksum, $file->getClientOriginalName(), $id]);
        // Serialise identical bytes before the transaction opens, so the winner's commit is
        // visible to the waiter. A gap lock on an empty checksum index deadlocks two inserts.
        $checksumLock = $this->lockChecksum($checksum);
        $stored = null;
        try {
            return DB::transaction(function () use ($actor, $input, $file, $id, $requestKey, $scope, $hash, $checksum, $confirmDuplicate, $forceNewVersion, &$stored) {
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
                if (! ($actor instanceof User) && in_array($source, ['OAS', 'ES'], true)) {
                    abort_if(preg_match('/medical|health|clinical|diagnos|vaccin/i', $input['category'] ?? ''), 422, 'Medical intake requires separately agreed access rules.');
                }
                if (! $id) {
                    $existingDoc = DB::table('document_versions as v')
                        ->join('documents as d', 'd.id', '=', 'v.document_id')
                        ->where('v.checksum', $checksum)
                        ->where('d.school_id', $actor->school_id)
                        ->where('d.campus', $actor->campus)
                        ->where('d.source', $source)
                        ->whereNull('d.trashed_at')
                        ->select('d.id', 'd.revision')
                        ->first();

                    if ($existingDoc) {
                        if ($forceNewVersion) {
                            $id = $existingDoc->id;
                        } elseif (! $confirmDuplicate) {
                            $matches = DuplicateDetection::matches($actor, $checksum, null);
                            throw new DuplicateFileException($matches ?: [['document_id' => $existingDoc->id]], $existingDoc->id);
                        }
                    }
                }
                if ($id) {
                    $doc = DB::table('documents')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
                    $this->authorize($actor, $doc, true);
                    abort_if($doc->trashed_at, 409, 'Restore this document before adding a version.');
                    if (! $forceNewVersion && isset($input['revision'])) {
                        abort_unless($doc->revision === (int) $input['revision'], 409, 'Document changed. Reload and retry.');
                    }
                    // The document being versioned is excluded: re-versioning it is not a dup.
                    $duplicates = $this->duplicateMatches($actor, $checksum, $id, true);
                    DB::table('documents')->where('id', $id)->update(['revision' => $doc->revision + 1, 'updated_at' => now()]);
                } else {
                    if ($actor instanceof User) {
                        Gate::forUser($actor)->authorize('document.create', $source);
                    }
                    if (! empty($input['folder_id'])) {
                        $folder = Access::scope(DB::table('folders'), $actor)->where('id', $input['folder_id'])->where('source', $source)->first();
                        abort_unless($folder && $actor instanceof User && ($folder->owner_id === $actor->id || Access::manages($actor, $folder)), 403);
                    }
                    $duplicates = $this->duplicateMatches($actor, $checksum, null, $confirmDuplicate);
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
                Access::audit($actor, 'document.uploaded', $doc, ['version_id' => $version, 'scan_status' => $status]
                    + ($duplicates ? ['duplicate_of' => array_values(array_unique(array_column($duplicates, 'document_id')))] : []));
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
        } finally {
            $this->releaseChecksumLock($checksumLock);
        }
    }

    /**
     * MySQL advisory lock scoped to one checksum. SQLite serialises writers already.
     * Returns the lock name when held, null when no lock is needed.
     */
    private function lockChecksum(string $checksum): ?string
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return null;
        }
        $name = 'dms:dup:'.substr($checksum, 0, 40);
        $acquired = (int) (DB::selectOne('SELECT GET_LOCK(?, ?) AS acquired', [$name, 5])->acquired ?? 0);
        abort_unless($acquired === 1, 409, 'Another upload of the same file is in progress. Retry with the same submission key.');

        return $name;
    }

    private function releaseChecksumLock(?string $name): void
    {
        if ($name) {
            DB::selectOne('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    public function authorize(object $actor, object $doc, bool $edit = false): void
    {
        abort_unless($actor->school_id === $doc->school_id && $actor->campus === $doc->campus, 404);
        if ($actor instanceof User) {
            Access::document($actor, $doc->id);
            Gate::forUser($actor)->authorize('document.view', $doc);
            if ($edit) {
                Gate::forUser($actor)->authorize('document.edit', $doc);
            }
        } else {
            // Connector read-back follows the same classification scope, with
            // Restricted documents never exposed to source connectors.
            abort_unless(Access::visibleTo(DB::table('documents')->where('id', $doc->id), $actor)->exists(), 404);
        }
    }

    public function download(object $actor, string $versionId, bool $preview = false)
    {
        $version = DB::table('document_versions')->where('id', $versionId)->first() ?? abort(404);
        $doc = DB::table('documents')->where('id', $version->document_id)->first() ?? abort(404);
        $this->authorize($actor, $doc);
        abort_if($doc->trashed_at, 404);
        if ($actor instanceof User) {
            $pin = MemoService::circulatedVersion($actor, $doc);
            abort_if($pin && $pin !== $versionId, 403, 'This memo version has not been released.');
        } else {
            $pin = DB::table('memo_publications')->where('document_id', $doc->id)->value('version_id');
            abort_if($pin && $pin !== $versionId, 403, 'This memo version has not been released.');
        }
        if ($actor instanceof User && $doc->owner_id !== $actor->id
            && ! Gate::forUser($actor)->allows('document.all-versions', $doc)) {
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

            if (in_array($version->mime, ['text/plain', 'text/csv', 'application/json'])) {
                $headers['Content-Type'] = 'text/html; charset=UTF-8';
                $headers['Content-Security-Policy'] = "sandbox; default-src 'none'; style-src 'unsafe-inline'";

                return response()->view('partials.file-text-preview', ['filename' => $version->filename, 'content' => file_get_contents($path)], 200, $headers);
            }
            // Native PDF viewers require a document response without the sandbox directive.
            if ($version->mime === 'application/pdf') {
                $headers['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'self'";
            }

            return response()->file($path, $headers);
        }

        return response()->download($path, $version->filename, $headers);
    }

    /**
     * Advisory duplicate check performed inside the upload transaction.
     *
     * Same-checksum uploads are serialised by the advisory lock taken in upload().
     * The lock is taken before the transaction opens, so the waiter's first read
     * observes the winner's committed row. Nothing is written and no file is stored
     * before this decision.
     *
     * @return array<int, array<string, mixed>>
     */
    private function duplicateMatches(object $actor, string $checksum, ?string $documentId, bool $confirmed): array
    {
        if (! config('dms.duplicate_detection', true)) {
            return [];
        }
        $matches = DuplicateDetection::matches($actor, $checksum, $documentId);
        if ($matches && ! $confirmed) {
            throw new DuplicateFileException($matches, $matches[0]['document_id'] ?? null);
        }

        return $matches;
    }
}
