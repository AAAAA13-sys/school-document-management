<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Enums\Source;
use App\Http\Requests\ActionRequest;
use App\Http\Requests\ReadRequest;
use App\Services\Access;
use App\Services\AccountChangeService;
use App\Services\InvitationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function index(ReadRequest $request, string $section = 'overview')
    {
        $user = $request->user();
        abort_unless(in_array($section, ['overview', 'repository', 'accounts', 'connectors', 'audit', 'scans']), 404);
        Gate::forUser($user)->authorize('admin.section', $section);
        // Application area marker for the shared layout; it is not a document source.
        $source = 'ALL';
        $stats = ['documents' => Access::visibleTo(DB::table('documents'), $user)->whereNull('trashed_at')->count(),
            'accounts' => Access::administrationScope(DB::table('users'), $user)->where('active', true)->count(),
            'history' => Access::administrationScope(DB::table('history_receipts'), $user)->count(),
            'connectors' => Access::administrationScope(DB::table('connectors'), $user)->where('active', true)->distinct()->count('source'),
            'blocked' => Access::visibleTo(DB::table('documents'), $user, 'documents.')
                ->join('document_versions', 'documents.id', '=', 'document_versions.document_id')
                ->where('scan_status', '!=', 'Clean')->count()];
        $workspaceCounts = Access::visibleTo(DB::table('documents'), $user)->whereNull('trashed_at')->selectRaw('source, COUNT(*) AS total')->groupBy('source')->pluck('total', 'source')->all();
        $accounts = Access::administrationScope(DB::table('users'), $user)->when($user->isAdmin(), fn ($q) => $q->where('role', Role::Staff->value))->orderBy('name')->get(['id', 'name', 'email', 'role', 'source', 'active']);
        $connectors = Access::administrationScope(DB::table('connectors'), $user)->orderBy('source')->get(['id', 'name', 'source', 'active', 'last_seen_at', 'created_at']);
        $auditQuery = Access::administrationScope(DB::table('audit_entries as a'), $user, 'a.')
            ->leftJoin('users as u', 'u.id', '=', 'a.actor_id')->select('a.*', 'u.name as actor_name');
        // Audit rows can name a document; do not disclose activity on a document
        // the viewer cannot see. Non-document audit entries remain visible.
        $visibleDocuments = Access::visibleTo(DB::table('documents'), $user)->select('id');
        $auditQuery->where(fn ($q) => $q->whereNull('a.document_id')->orWhereIn('a.document_id', $visibleDocuments));
        if ($section === 'audit') {
            if ($request->filled('source')) {
                $auditQuery->where('a.source', $request->input('source'));
            }
            if ($request->filled('q')) {
                $auditQuery->where(fn ($q) => $q->where('a.action', 'like', '%'.$request->input('q').'%')->orWhere('u.name', 'like', '%'.$request->input('q').'%')->orWhere('a.document_id', 'like', '%'.$request->input('q').'%'));
            }
        }
        $audits = $auditQuery->orderByDesc('a.id')->paginate(30)->withQueryString();
        $docs = Access::documents($user);
        if ($request->filled('q')) {
            $docs->where(fn ($q) => $q->where('title', 'like', '%'.$request->input('q').'%')->orWhere('reference', 'like', '%'.$request->input('q').'%'));
        }
        if ($request->filled('source')) {
            $docs->where('source', $request->input('source'));
        }
        if ($request->filled('classification')) {
            $docs->where('classification', $request->input('classification'));
        }
        $documents = $docs->orderByDesc('updated_at')->paginate(20)->withQueryString();
        $scans = Access::visibleTo(
            DB::table('document_versions')->join('documents', 'documents.id', '=', 'document_versions.document_id'),
            $user,
            'documents.',
        )->where('scan_status', '!=', 'Clean')
            ->select('document_versions.*', 'documents.title', 'documents.source')
            ->orderByDesc('document_versions.created_at')->paginate(20)->withQueryString();

        if ($request->expectsJson()) {
            return response()->json([
                'section' => $section,
                'stats' => $stats,
                'workspace_counts' => $workspaceCounts,
                'accounts' => $section === 'accounts' || $section === 'overview' ? $accounts : null,
                'connectors' => $section === 'connectors' || $section === 'overview' ? $connectors : null,
                'audits' => $section === 'audit' ? $audits : null,
                'documents' => $section === 'repository' ? $documents : null,
                'scans' => $section === 'scans' ? $scans : null,
            ]);
        }

        return view('admin', compact('workspaceCounts', 'source', 'section', 'stats', 'accounts', 'connectors', 'audits', 'documents', 'scans'));
    }

    public function account(ActionRequest $request, ?string $account = null)
    {
        $actor = $request->user();
        Gate::forUser($actor)->authorize('accounts.manage');
        $data = $request->validated();
        $data['source'] = $data['role'] === Role::SuperAdmin->value ? null : Source::normalize($data['source'] ?? null);
        if ($account) {
            $status = app(AccountChangeService::class)->submit($actor, $account, $data);

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => $status,
                    'message' => $status === 'pending' ? 'Account change submitted for Super Admin approval.' : 'Account updated.',
                ]);
            }

            return back()->with('status', $status === 'pending' ? 'Account change submitted for Super Admin approval.' : 'Account updated.');
        }
        $service = app(InvitationService::class);
        $id = $service->request($actor, $data);
        if ($actor->isSuperAdmin()) {
            $service->deliver($actor, $id);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $id,
                'status' => $actor->isSuperAdmin() ? 'delivered' : 'pending',
                'message' => $actor->isSuperAdmin() ? 'Invitation email sent.' : 'Staff invitation submitted for Super Admin approval.',
            ], 201);
        }

        return back()->with('status', $actor->isSuperAdmin() ? 'Invitation email sent.' : 'Staff invitation submitted for Super Admin approval.');
    }

    public function connector(ActionRequest $request)
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('connectors.manage');
        abort_unless(in_array($request->validated('source'), \App\Services\WorkspaceRegistry::codes($user, true), true), 422, 'Choose an active ERP workspace.');
        $token = Str::random(64);
        $id = null;
        DB::transaction(function () use ($user, $request, $token, &$id) {
            $id = DB::table('connectors')->insertGetId($request->validated() + ['school_id' => $user->school_id, 'campus' => $user->campus, 'token_hash' => hash('sha256', $token), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            Access::audit($user, 'connector.created', details: ['connector_id' => $id]);
        });

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Connector created. Copy its token now; it will not be shown again.',
                'connector_token' => $token,
                'connector_id' => $id,
            ], 201);
        }

        return back()->with('connector_token', $token)->with('status', 'Connector created. Copy its token now; it will not be shown again.');
    }

    public function revoke(ActionRequest $request, string $connector)
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('connectors.manage');
        DB::transaction(function () use ($user, $connector) {
            $row = Access::administrationScope(DB::table('connectors'), $user)->where('id', $connector)->lockForUpdate()->first() ?? abort(404);
            DB::table('connectors')->where('id', $row->id)->update(['active' => false, 'updated_at' => now()]);
            Access::audit($user, 'connector.revoked', details: ['connector_id' => $row->id]);
        });

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Connector revoked.',
                'status' => 'revoked',
            ]);
        }

        return back()->with('status', 'Connector revoked.');
    }

    public function legalHolds(\Illuminate\Http\Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->isSuperAdmin(), 403, 'Legal holds require Super Admin authority.');
        $holds = \App\Models\AuditLegalHold::where('school_id', $actor->school_id)
            ->where('campus', $actor->campus)
            ->with('actor:id,name,email')
            ->orderByDesc('created_at')
            ->paginate(30);

        if ($request->expectsJson()) {
            return response()->json($holds);
        }

        return view('admin-legal-holds', compact('holds'));
    }

    public function storeLegalHold(\Illuminate\Http\Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->isSuperAdmin(), 403, 'Legal holds require Super Admin authority.');
        $data = $request->validate([
            'reason' => 'required|string|min:1|max:2000',
        ]);

        $hold = DB::transaction(function () use ($actor, $data) {
            $id = DB::table('audit_legal_holds')->insertGetId([
                'school_id' => $actor->school_id,
                'campus' => $actor->campus,
                'actor_id' => $actor->id,
                'reason' => trim($data['reason']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Access::audit($actor, 'audit.legal_hold.created', null, ['hold_id' => $id, 'reason' => $data['reason']]);
            return DB::table('audit_legal_holds')->where('id', $id)->first();
        });

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Legal hold recorded. Audit history is retained; disposal suspended.',
                'legal_hold' => $hold,
            ], 201);
        }

        return back()->with('status', 'Legal hold recorded.');
    }

    public function releaseLegalHold(\Illuminate\Http\Request $request, int $hold)
    {
        $actor = $request->user();
        abort_unless($actor->isSuperAdmin(), 403, 'Legal holds require Super Admin authority.');
        $data = $request->validate([
            'reason' => 'required|string|min:1|max:2000',
        ]);

        DB::transaction(function () use ($actor, $hold, $data) {
            $row = Access::scope(DB::table('audit_legal_holds'), $actor)->where('id', $hold)->whereNull('released_at')->lockForUpdate()->first() ?? abort(404);
            DB::table('audit_legal_holds')->where('id', $row->id)->update([
                'released_at' => now(),
                'updated_at' => now(),
            ]);
            Access::audit($actor, 'audit.legal_hold.released', null, ['hold_id' => $row->id, 'reason' => $data['reason']]);
        });

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Legal hold released.',
                'status' => 'released',
            ]);
        }

        return back()->with('status', 'Legal hold released.');
    }
}
