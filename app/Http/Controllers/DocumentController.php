<?php

namespace App\Http\Controllers;

use App\Exceptions\AccessChangeException;
use App\Http\Requests\ActionRequest;
use App\Http\Requests\UploadRequest;
use App\Services\Access;
use App\Services\DocumentMoves;
use App\Services\DocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DocumentController extends Controller
{
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
            $result = app(\App\Services\SensitiveChangeService::class)->submit($request->user(), $current, 'classification', $request->validated());
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
            if ($classification !== $doc->classification) Gate::forUser($user)->authorize('document.classify', [$doc, $classification]);
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

        if ($request->expectsJson()) return response()->json($result);
        return back()->with('status', $result['message']);
    }

    public function trash(ActionRequest $request, string $document)
    {
        $restore = $request->route()->getName() === 'documents.restore';
        $result = app(\App\Services\SensitiveChangeService::class)->submit($request->user(), Access::document($request->user(), $document), $restore ? 'restore' : 'trash', $request->validated());
        $message = $result['status'] === 'pending' ? 'Request submitted. Live document unchanged until approval.' : ($restore ? 'Document restored.' : 'Document moved to trash. All versions preserved.');
        return $request->expectsJson() ? response()->json($result + ['message' => $message], $result['status'] === 'pending' ? 202 : 200) : back()->with('status', $message);
    }

    public function star(ActionRequest $request, string $document)
    {
        DB::transaction(function () use ($request, $document) {
            $user = $request->user();
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $doc = Access::document($user, $document);
            Gate::forUser($user)->authorize('document.star', $doc);
            $q = DB::table('stars')->where('document_id', $document)->where('user_id', $user->id);
            if ($q->exists()) {
                $q->delete();
            } else {
                DB::table('stars')->insert(['document_id' => $document, 'user_id' => $user->id]);
            }
            Access::audit($user, 'document.star_toggled', $doc);
        });

        return back();
    }

    public function download(Request $request, string $version, DocumentService $service)
    {
        return $service->download($request->user(), $version, $request->route()->getName() === 'files.preview');
    }
}
