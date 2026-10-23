<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Access;
use App\Services\WorkspaceRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkspaceRegistryController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isSuperAdmin(), 403);
        $workspaces = WorkspaceRegistry::query($request->user(), false)->orderBy('name')->get();
        $users = Access::scope(DB::table('users'), $request->user())->where('active', true)->where('role', '!=', 'super_admin')->orderBy('name')->get();
        // TODO(BackendAgent): Ensure $memberships query is maintained for the workspace registry view
        $memberships = DB::table('workspace_memberships')->whereIn('workspace_id', $workspaces->pluck('id'))->get()->groupBy('workspace_id');
        if ($request->expectsJson()) {
            return response()->json([
                'workspaces' => $workspaces,
                'users' => $users,
                'memberships' => $memberships,
            ]);
        }
        return view('workspaces-registry', compact('workspaces', 'users', 'memberships') + ['section' => 'workspaces', 'source' => 'ALL']);
    }

    public function store(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->isSuperAdmin(), 403);
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);
        $data = $request->validate(['code' => ['required', 'regex:/^[A-Z][A-Z0-9_-]{0,63}$/D', Rule::unique('workspaces')->where(fn ($q) => $q->where('school_id', $actor->school_id)->where('campus', $actor->campus))], 'name' => 'required|string|max:150', 'kind' => 'required|in:erp,internal']);
        DB::transaction(function () use ($actor, $data) {
            DB::table('workspaces')->insert($data + ['school_id' => $actor->school_id, 'campus' => $actor->campus, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            Access::audit($actor, 'workspace.created', details: $data, source: $data['code']);
        });
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Workspace created.',
                'workspace' => $data + ['status' => 'active'],
            ], 201);
        }
        return back()->with('status', 'Workspace created.');
    }

    public function update(Request $request, int $workspace)
    {
        $actor = $request->user();
        abort_unless($actor->isSuperAdmin(), 403);
        $row = WorkspaceRegistry::query($actor, false)->where('id', $workspace)->first() ?? abort(404);
        $data = $request->validate(['name' => 'required|string|max:150', 'status' => 'required|in:active,archived', 'code' => 'prohibited', 'kind' => 'prohibited']);
        DB::transaction(function () use ($actor, $row, $data) {
            DB::table('workspaces')->where('id', $row->id)->update($data + ['updated_at' => now()]);
            Access::audit($actor, 'workspace.updated', details: $data, source: $row->code);
        });
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Workspace updated.',
                'workspace' => array_merge((array) $row, $data),
            ]);
        }
        return back()->with('status', 'Workspace updated.');
    }

    public function membership(Request $request, int $workspace)
    {
        $actor = $request->user();
        abort_unless($actor->isSuperAdmin(), 403);
        $row = WorkspaceRegistry::query($actor, false)->where('id', $workspace)->first() ?? abort(404);
        $data = $request->validate(['user_id' => 'required|integer', 'action' => 'required|in:grant,revoke']);
        $user = User::where('school_id', $actor->school_id)->where('campus', $actor->campus)->findOrFail($data['user_id']);
        abort_if($user->isSuperAdmin(), 422, 'Super Admin already has full workspace access.');
        DB::transaction(function () use ($actor, $row, $user, $data) {
            if ($data['action'] === 'grant') {
                WorkspaceRegistry::assign($user, $row->code);
            } else {
                DB::table('workspace_memberships')->where('workspace_id', $row->id)->where('user_id', $user->id)->delete();
                if ($user->source === $row->code) {
                    $user->update(['source' => null]);
                }
            }
            Access::audit($actor, 'workspace.membership.'.$data['action'], details: ['user_id' => $user->id], source: $row->code);
        });
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Workspace membership updated.',
                'action' => $data['action'],
                'user_id' => $user->id,
                'workspace_id' => $row->id,
            ]);
        }
        return back()->with('status', 'Workspace membership updated.');
    }
}
