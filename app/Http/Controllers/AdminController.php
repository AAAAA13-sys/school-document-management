<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Enums\Source;
use App\Http\Requests\ActionRequest;
use App\Http\Requests\ReadRequest;
use App\Models\User;
use App\Services\Access;
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

        return view('admin', compact('workspaceCounts', 'source', 'section', 'stats', 'accounts', 'connectors', 'audits', 'documents', 'scans'));
    }

    public function account(ActionRequest $request, ?string $account = null)
    {
        $admin = $request->user();
        Gate::forUser($admin)->authorize('accounts.manage');
        $data = $request->validated();
        $data['source'] = $data['role'] === Role::SuperAdmin->value ? null : Source::normalize($data['source'] ?? null);
        DB::transaction(function () use ($admin, $account, $data) {
            if ($account) {
                $query = User::where('school_id', $admin->school_id)
                    ->where('campus', $admin->campus)
                    ->whereKey($account);
                if ($admin->isAdmin() && $admin->source) {
                    // Guessing an account in another source is a 404, not a
                    // disclosure that the account exists.
                    $query->where('source', $admin->source);
                }
                $row = $query->lockForUpdate()->first() ?? abort(404);
                Gate::forUser($admin)->authorize('update', $row);
                abort_if($row->id === $admin->id && ($data['role'] !== $row->role || $data['source'] !== $row->source || ! $data['active']), 422, 'You cannot demote or deactivate yourself.');
                Gate::forUser($admin)->authorize('changeRole', [$row, $data['role']]);
                Gate::forUser($admin)->authorize('changeSource', [$row, $data['source']]);
                if (empty($data['password'])) {
                    unset($data['password']);
                }
                $accessChanged = $row->role !== $data['role'] || $row->source !== $data['source'];
                $row->fill($data);
                $row->save();
                if (isset($data['password']) || ! $data['active'] || $accessChanged) {
                    $row->increment('auth_version');
                    $row->setRememberToken(Str::random(60));
                    $row->save();
                    DB::table('sessions')->where('user_id', $row->id)->delete();
                }
            } else {
                Gate::forUser($admin)->authorize('accounts.role', $data['role']);
                Gate::forUser($admin)->authorize('accounts.source', $data['source']);
                $row = new User($data);
                // These are server-assigned and never accepted from the form.
                $row->school_id = $admin->school_id;
                $row->campus = $admin->campus;
                $row->save();
            }
            Access::audit($admin, $account ? 'account.updated' : 'account.created', details: ['account_id' => $row->id]);
        });

        return back()->with('status', 'Account saved.');
    }

    public function connector(ActionRequest $request)
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('connectors.manage');
        $token = Str::random(64);
        DB::transaction(function () use ($user, $request, $token) {
            $id = DB::table('connectors')->insertGetId($request->validated() + ['school_id' => $user->school_id, 'campus' => $user->campus, 'token_hash' => hash('sha256', $token), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            Access::audit($user, 'connector.created', details: ['connector_id' => $id]);
        });

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

        return back()->with('status', 'Connector revoked.');
    }
}
