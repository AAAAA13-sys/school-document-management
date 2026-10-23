<?php

namespace App\Http\Controllers;

use App\Exceptions\DuplicateFileException;
use App\Http\Requests\BatchUploadRequest;
use App\Services\Access;
use App\Services\DocumentService;
use App\Services\HistoryService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BatchUploadController extends Controller
{
    public function __invoke(BatchUploadRequest $request, DocumentService $service)
    {
        $actor = $request->user();
        $input = $request->validated();
        $source = $input['source'];
        Gate::forUser($actor)->authorize('document.create', $source);
        $parent = $input['folder_id'] ?? null;
        if ($parent) {
            $folderQuery = Access::folders($actor)->where('id', $parent)->where('source', $source);

            abort_unless($folderQuery->exists(), 404);
        }
        $results = [];
        // Each file commits separately; replay the same batch key to resume without duplicate versions.
        foreach ($request->file('files') as $i => $file) {
            $parts = explode('/', str_replace('\\', '/', $input['paths'][$i]));
            $filename = array_pop($parts);
            $folder = DB::transaction(function () use ($actor, $parts, $parent, $source) {
                DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
                $current = $parent;
                foreach ($parts as $name) {
                    $row = Access::scope(DB::table('folders'), $actor)->where('owner_id', $actor->id)->where('source', $source)->where('parent_id', $current)->where('name', $name)->first();
                    if ($row) {
                        $current = $row->id;

                        continue;
                    }
                    $previous = $current;
                    $current = (string) Str::uuid();
                    DB::table('folders')->insert(['id' => $current, 'owner_id' => $actor->id, 'parent_id' => $previous, 'name' => $name, 'source' => $source, 'school_id' => $actor->school_id, 'campus' => $actor->campus, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
                    Access::audit($actor, 'folder.created', details: ['folder_id' => $current]);
                }

                return $current;
            });
            try {
                [$body,$status] = $service->upload($actor, ['source' => $source, 'title' => pathinfo($filename, PATHINFO_FILENAME) ?: $filename, 'folder_id' => $folder, 'classification' => $input['classification'], 'confirm_duplicate' => $input['confirm_duplicate'] ?? false], $file, null, HistoryService::hash([$input['submission_key'], $input['paths'][$i]]));
                $results[] = ['path' => $input['paths'][$i], 'status' => $status] + $body;
            } catch (HttpException $e) {
                $results[] = ['path' => $input['paths'][$i], 'status' => $e->getStatusCode(), 'message' => $e->getMessage()]
                    + ($e instanceof DuplicateFileException ? ['code' => 'duplicate_file', 'duplicates' => $e->matches] : []);
            }
        }
        $failed = collect($results)->contains(fn ($row) => $row['status'] >= 400);

        return response()->json(['results' => $results], $failed ? 207 : 201);
    }
}
