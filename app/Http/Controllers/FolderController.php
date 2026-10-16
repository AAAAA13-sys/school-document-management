<?php

namespace App\Http\Controllers;

use App\Http\Requests\ActionRequest;
use App\Services\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FolderController extends Controller
{
    public function create(ActionRequest $request)
    {
        $user = $request->user();
        $data = $request->validated();
        abort_unless($user->role === 'super_admin' ? $data['source'] === 'DMS' : in_array($data['source'], $user->sources()), 403);
        DB::transaction(function () use ($user, $data) {
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            if (! empty($data['parent_id'])) {
                abort_unless(Access::scope(DB::table('folders'), $user)->where('id', $data['parent_id'])->when($user->role !== 'admin', fn ($q) => $q->where('owner_id', $user->id))->where('source', $data['source'])->exists(), 403);
            }
            DB::table('folders')->insert($data + ['id' => (string) Str::uuid(), 'owner_id' => $user->id, 'school_id' => $user->school_id, 'campus' => $user->campus, 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            Access::audit($user, 'folder.created');
        });

        return back()->with('status', 'Folder created.');
    }

    public function update(ActionRequest $request, string $folder)
    {
        DB::transaction(function () use ($request, $folder) {
            $user = $request->user();
            DB::table('users')->where('id', $user->id)->lockForUpdate()->first();
            $row = Access::scope(DB::table('folders'), $user)->when($user->role === 'admin', fn ($q) => $q->where('source', $user->source), fn ($q) => $q->where('owner_id', $user->id))->where('id', $folder)->lockForUpdate()->first() ?? abort(404);
            abort_unless($row->revision === (int) $request->validated('revision'), 409, 'Folder changed. Reload and retry.');
            $parent = $request->validated('parent_id');
            $seen = [$folder];
            while ($parent) {
                abort_if(in_array($parent, $seen), 422, 'A folder cannot be moved into itself or its descendants.');
                $seen[] = $parent;
                $p = Access::scope(DB::table('folders'), $user)->when($user->role !== 'admin', fn ($q) => $q->where('owner_id', $user->id))->where('source', $row->source)->where('id', $parent)->first() ?? abort(404);
                $parent = $p->parent_id;
            }
            DB::table('folders')->where('id', $folder)->update($request->safe()->only(['name', 'parent_id']) + ['revision' => $row->revision + 1, 'updated_at' => now()]);
            Access::audit($user, 'folder.updated');
        });

        return back()->with('status', 'Folder updated.');
    }
}
