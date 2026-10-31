<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessChangeException;
use App\Http\Requests\ActionRequest;
use App\Http\Requests\UploadRequest;
use App\Services\Access;
use App\Services\DocumentMoves;
use App\Services\DocumentService;
use App\Services\SensitiveChangeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{
    public function index(Request $request, DocumentService $service)
    {
        $user = $request->user();
        $source = $request->input('source');
        if ($source) {
            $source = strtoupper($source);
            Gate::forUser($user)->authorize('workspaces.access', $source);
        }

        $view = $request->input('view') ?: 'all';
        $query = Access::documents($user);

        if ($source && $view !== 'shared') {
            $query->where('source', $source);
        }

        $foldersForSharing = Access::inheritedFolderIds($user);

        if ($view === 'trash') {
            $query->whereNotNull('trashed_at');
        } else {
            $query->whereNull('trashed_at');
        }

        if ($view === 'mine') {
            $query->where('owner_id', $user->id);
        } elseif ($view === 'shared') {
            Access::sharedDocuments($query, $user, $foldersForSharing);
        } elseif ($view === 'starred') {
            $query->whereIn('id', DB::table('stars')->select('document_id')->where('user_id', $user->id));
        }

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(fn ($sub) => $sub->where('title', 'like', '%'.$q.'%')->orWhere('reference', 'like', '%'.$q.'%'));
        }

        if ($request->filled('folder_id')) {
            $query->where('folder_id', $request->input('folder_id'));
        }

        $perPage = min(max((int) $request->input('per_page', 15), 1), 100);
        $documents = $query->orderByDesc('updated_at')->paginate($perPage)->withQueryString();

        $versions = $service->listingVersions($user, $documents);

        $starredIds = DB::table('stars')->where('user_id', $user->id)->pluck('document_id')->all();

        if ($request->expectsJson()) {
            $items = $documents->map(function ($doc) use ($user, $versions, $starredIds) {
                $v = $versions[$doc->id] ?? null;

                return [
                    'id' => $doc->id,
                    'owner_id' => $doc->owner_id,
                    'folder_id' => $doc->folder_id,
                    'source' => $doc->source,
                    'school_id' => $doc->school_id,
                    'campus' => $doc->campus,
                    'title' => $doc->title,
                    'subject' => $doc->subject,
                    'reference' => $doc->reference,
                    'category' => $doc->category,
                    'classification' => $doc->classification,
                    'revision' => $doc->revision,
                    'trashed_at' => $doc->trashed_at,
                    'created_at' => $doc->created_at,
                    'updated_at' => $doc->updated_at,
                    'starred' => in_array($doc->id, $starredIds, true),
                    'latest_version' => $v ? [
                        'id' => $v->id,
                        'number' => $v->number,
                        'filename' => $v->filename,
                        'mime' => $v->mime,
                        'size' => $v->size,
                        'checksum' => $v->checksum,
                        'scan_status' => $v->scan_status,
                        'created_at' => $v->created_at,
                    ] : null,
                    'permissions' => [
                        'can_edit' => Gate::forUser($user)->allows('document.edit', $doc),
                        'can_manage' => Gate::forUser($user)->allows('document.manage', $doc),
                        'can_share' => Gate::forUser($user)->allows('document.share', $doc),
                    ],
                ];
            });

            return response()->json([
                'data' => $items,
                'meta' => [
                    'current_page' => $documents->currentPage(),
                    'last_page' => $documents->lastPage(),
                    'per_page' => $documents->perPage(),
                    'total' => $documents->total(),
                ],
            ]);
        }

        $targetSource = $source ?: ($user->primarySource() ?: 'EMS');

        return redirect('/workspaces/'.strtolower($targetSource).'/documents');
    }

    public function checkDuplicate(Request $request)
    {
        $user = $request->user();
        $checksum = $request->input('checksum');
        $source = $request->input('source');

        if (! $checksum) {
            return response()->json(['is_duplicate' => false]);
        }

        $matches = \App\Services\DuplicateDetection::matches($user, $checksum, null);

        if (empty($matches) && $source) {
            $existing = DB::table('document_versions as v')
                ->join('documents as d', 'd.id', '=', 'v.document_id')
                ->where('v.checksum', $checksum)
                ->where('d.school_id', $user->school_id)
                ->where('d.campus', $user->campus)
                ->where('d.source', $source)
                ->whereNull('d.trashed_at')
                ->select('d.id as document_id', 'd.title')
                ->first();

            if ($existing) {
                $matches = [[
                    'document_id' => $existing->document_id,
                    'title' => $existing->title,
                ]];
            }
        }

        return response()->json([
            'is_duplicate' => ! empty($matches),
            'duplicates' => array_values($matches),
        ]);
    }

    public function upload(UploadRequest $request, DocumentService $service, ?string $document = null)
    {
        [$result, $status] = $service->upload($request->user(), $request->safe()->except(['file', 'submission_key']), $request->file('file'), $document, $request->validated('submission_key'));
        if ($request->expectsJson()) {
            return response()->json($result, $status);
        }

        return redirect('/documents/'.$result['document_id'])->with('status', 'Version saved. Scan status: '.$result['scan_status'].'.');
    }

    public function update(ActionRequest $request, string $document)
    {
        $current = Access::document($request->user(), $document);
        if (! $request->user()->isSuperAdmin() && $request->validated('classification', $current->classification) !== $current->classification) {
            Gate::forUser($request->user())->authorize('document.classify', [$current, $request->validated('classification')]);
            abort_if($request->validated('title') !== $current->title || $request->validated('folder_id', $current->folder_id) !== $current->folder_id, 422, 'Submit a classification request separately from renaming or moving the document.');
            $result = app(SensitiveChangeService::class)->submit($request->user(), $current, 'classification', $request->validated());
            $result['message'] = $result['status'] === 'pending' ? 'Classification request submitted. Live document unchanged.' : 'Classification updated.';

            return $request->expectsJson() ? response()->json($result, $result['status'] === 'pending' ? 202 : 200) : back()->with('status', $result['message']);
        }
        $result = DB::transaction(function () use ($request, $document) {
            $user = $request->user();
            Access::document($user, $document);
            $doc = DB::table('documents')->where('id', $document)->lockForUpdate()->first();
            Gate::forUser($user)->authorize('document.edit', $doc);
            abort_if($doc->trashed_at, 409, 'Restore this document first.');
            abort_unless($doc->revision === (int) $request->validated('revision'), 409, 'Document changed. Reload and retry.');
            $classification = $request->validated('classification', $doc->classification);
            if ($classification !== $doc->classification) {
                Gate::forUser($user)->authorize('document.classify', [$doc, $classification]);
            }
            $previousClassification = $doc->classification;
            $previousFolder = $doc->folder_id;
            $folder = $request->validated('folder_id');
            $moved = $folder !== $previousFolder;
            $accessChange = ['gained' => [], 'reduced' => [], 'broadened' => false];
            $destination = null;
            if ($moved) {
                Gate::forUser($user)->authorize('document.manage', $doc);
                if ($folder) {
                    $destinationQuery = Access::scope(DB::table('folders'), $user)->where('id', $folder)
                        ->where('source', $doc->source);
                    if ($user->isStaff()) {
                        $destinationQuery->where('owner_id', $user->id);
                    }
                    $destination = $destinationQuery->first();
                    abort_unless($destination, 404);
                }
                // A move silently re-scopes inherited access; warn before broadening it.
                $accessChange = DocumentMoves::audienceDelta($doc, $folder, $classification);
                if ($accessChange['broadened'] && ! $request->boolean('confirm_move')) {
                    throw new AccessChangeException(['gained' => count($accessChange['gained']),
                        'folder' => $destination?->name, 'permissions' => array_count_values($accessChange['gained'])]);
                }
            }
            DB::table('documents')->where('id', $document)->update(['title' => $request->validated('title'), 'classification' => $classification, 'folder_id' => $folder, 'revision' => $doc->revision + 1, 'updated_at' => now()]);
            $doc->revision++;
            $details = ['classification_before' => $previousClassification, 'classification_after' => $classification,
                'folder_before' => $previousFolder, 'folder_after' => $folder];
            if ($moved) {
                $details += ['access_gained' => array_keys($accessChange['gained']), 'access_reduced' => array_keys($accessChange['reduced'])];
            }
            Access::audit($user, 'document.updated', $doc, $details);
            Access::event($doc, $moved ? 'document.moved' : 'document.updated');
            $message = $moved ? 'Document moved to the selected folder.' : 'Document updated.';
            if ($moved && $accessChange['gained']) {
                $message .= ' '.count($accessChange['gained']).' more '
                    .(count($accessChange['gained']) === 1 ? 'person' : 'people').' now have access through it.';
            }

            return ['message' => $message, 'revision' => $doc->revision, 'folder_id' => $folder,
                'access_gained' => count($accessChange['gained'])];
        });

        if ($request->expectsJson()) {
            return response()->json($result);
        }

        return back()->with('status', $result['message']);
    }

    public function trash(ActionRequest $request, string $document)
    {
        $restore = $request->route()->getName() === 'documents.restore';
        $result = app(SensitiveChangeService::class)->submit($request->user(), Access::document($request->user(), $document), $restore ? 'restore' : 'trash', $request->validated());
        $message = $result['status'] === 'pending' ? 'Request submitted. Live document unchanged until approval.' : ($restore ? 'Document restored.' : 'Document moved to trash. All versions preserved.');

        return $request->expectsJson() ? response()->json($result + ['message' => $message], $result['status'] === 'pending' ? 202 : 200) : back()->with('status', $message);
    }

    public function forceDelete(ActionRequest $request, string $document)
    {
        $user = $request->user();
        $doc = Access::document($user, $document);
        abort_unless($doc->trashed_at !== null, 409, 'Only trashed documents can be permanently deleted.');
        Gate::forUser($user)->authorize('document.manage', $doc);

        DB::transaction(function () use ($user, $doc) {
            $versions = DB::table('document_versions')->where('document_id', $doc->id)->get();
            foreach ($versions as $ver) {
                if ($ver->storage_key) {
                    \Illuminate\Support\Facades\Storage::disk('local')->delete($ver->storage_key);
                }
            }
            DB::table('memo_publications')->where('document_id', $doc->id)->delete();
            DB::table('sensitive_changes')->where('document_id', $doc->id)->delete();
            DB::table('stars')->where('document_id', $doc->id)->delete();
            DB::table('comments')->where('document_id', $doc->id)->delete();
            DB::table('shares')->where('document_id', $doc->id)->delete();
            DB::table('document_versions')->where('document_id', $doc->id)->delete();
            DB::table('documents')->where('id', $doc->id)->delete();

            Access::audit($user, 'document.permanently_deleted', null, [
                'document_id' => $doc->id,
                'title' => $doc->title,
                'source' => $doc->source,
            ]);
        });

        $message = 'Document permanently deleted.';
        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'deleted' => true]);
        }

        $source = in_array($doc->source, $user->sources(), true) ? $doc->source : ($user->sources()[0] ?? $user->source ?? 'EMS');
        return redirect('/workspaces/' . strtolower($source) . '/documents?view=trash')->with('status', $message);
    }

    public function star(ActionRequest $request, string $document)
    {
        $isStarred = DB::transaction(function () use ($request, $document) {
            $user = $request->user();
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $doc = Access::document($user, $document);
            Gate::forUser($user)->authorize('document.star', $doc);
            $q = DB::table('stars')->where('document_id', $document)->where('user_id', $user->id);
            if ($q->exists()) {
                $q->delete();
                $starred = false;
            } else {
                DB::table('stars')->insert(['document_id' => $document, 'user_id' => $user->id]);
                $starred = true;
            }
            Access::audit($user, 'document.star_toggled', $doc);

            return $starred;
        });

        if ($request->expectsJson()) {
            return response()->json(['starred' => $isStarred, 'document_id' => $document]);
        }

        return back();
    }

    public function download(Request $request, string $version, DocumentService $service)
    {
        return $service->download($request->user(), $version, $request->route()->getName() === 'files.preview');
    }
}
