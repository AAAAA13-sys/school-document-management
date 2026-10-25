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

        $subsystemStatuses = collect([
            (object)['code' => 'OAS', 'name' => 'Online Admission', 'department' => 'Admissions Office', 'status' => $connectors->where('source', 'OAS')->where('active', true)->isNotEmpty() ? 'Online' : 'Connected', 'latency' => 24, 'endpoint' => '/api/v1/applicant-packets', 'total' => $workspaceCounts['OAS'] ?? 0, 'sync_mode' => 'Realtime Push', 'queue' => 12, 'active' => true],
            (object)['code' => 'ES', 'name' => 'Enrollment System', 'department' => 'Office of the Registrar', 'status' => $connectors->where('source', 'ES')->where('active', true)->isNotEmpty() ? 'Online' : 'Connected', 'latency' => 18, 'endpoint' => '/api/v1/documents', 'total' => $workspaceCounts['ES'] ?? 0, 'sync_mode' => 'Event Stream', 'queue' => 8, 'active' => true],
            (object)['code' => 'EMS', 'name' => 'Cashier & Staff', 'department' => 'Cashier / Accounts', 'status' => $connectors->where('source', 'EMS')->where('active', true)->isNotEmpty() ? 'Online' : 'Connected', 'latency' => 32, 'endpoint' => '/api/v1/history', 'total' => $workspaceCounts['EMS'] ?? 0, 'sync_mode' => 'Batch CAS', 'queue' => 5, 'active' => true],
            (object)['code' => 'PMS', 'name' => 'Payroll Management', 'department' => 'Accounting & Payroll', 'status' => $connectors->where('source', 'PMS')->where('active', true)->isNotEmpty() ? 'Online' : 'Connected', 'latency' => 15, 'endpoint' => '/api/v1/documents', 'total' => $workspaceCounts['PMS'] ?? 0, 'sync_mode' => 'Encrypted Outbox', 'queue' => 3, 'active' => true],
            (object)['code' => 'DMS', 'name' => 'Document Storage', 'department' => 'Central Repository', 'status' => 'Online', 'latency' => 4, 'endpoint' => '/api/v1/documents', 'total' => $stats['documents'] ?? 0, 'sync_mode' => 'Private Store', 'queue' => $stats['blocked'] ?? 0, 'active' => true],
        ]);

        $applicantQueue = collect();
        if (\Illuminate\Support\Facades\Schema::hasTable('applicant_packets')) {
            $applicantQueue = DB::table('applicant_packets')
                ->where('school_id', $user->school_id)
                ->where('campus', $user->campus)
                ->orderByDesc('updated_at')
                ->limit(6)
                ->get()
                ->map(fn ($p) => (object)[
                    'applicant_id' => $p->applicant_id,
                    'name' => 'Applicant #' . substr(hash('crc32', $p->applicant_id), 0, 5),
                    'program' => 'Undergraduate Admissions',
                    'files_count' => count(json_decode($p->versions ?? '[]', true) ?: []),
                    'documents_summary' => 'Admitted applicant verification packet',
                    'verified' => (bool)$p->released,
                    'admitted' => true,
                    'released' => (bool)$p->released,
                    'updated_at' => $p->updated_at,
                ]);
        }
        if ($applicantQueue->isEmpty()) {
            $applicantQueue = collect([
                (object)['applicant_id'=>'OAS-2026-0042','name'=>'Elena Santos','program'=>'BS Computer Science','files_count'=>3,'documents_summary'=>'PSA Birth Certificate, Form 137, Exam Ticket','verified'=>true,'admitted'=>true,'released'=>true,'updated_at'=>now()->subMinutes(15)->toDateTimeString()],
                (object)['applicant_id'=>'OAS-2026-0043','name'=>'Marco Bautista','program'=>'BS Information Technology','files_count'=>3,'documents_summary'=>'PSA Birth Certificate, Form 138, Parent Affidavit','verified'=>false,'admitted'=>false,'released'=>false,'updated_at'=>now()->subMinutes(35)->toDateTimeString()],
                (object)['applicant_id'=>'OAS-2026-0044','name'=>'Chloe Mendoza','program'=>'BS Nursing','files_count'=>4,'documents_summary'=>'PSA Birth Certificate, Form 137, Good Moral','verified'=>true,'admitted'=>true,'released'=>true,'updated_at'=>now()->subHours(1)->toDateTimeString()],
                (object)['applicant_id'=>'OAS-2026-0045','name'=>'Rico De Guzman','program'=>'BS Accountancy','files_count'=>2,'documents_summary'=>'Form 138, Exam Ticket','verified'=>false,'admitted'=>false,'released'=>false,'updated_at'=>now()->subHours(2)->toDateTimeString()],
            ]);
        }

        $departmentTransactions = Access::administrationScope(DB::table('history_receipts'), $user)
            ->orderByDesc('id')
            ->limit(6)
            ->get();
        if ($departmentTransactions->isEmpty()) {
            $departmentTransactions = collect([
                (object)['id'=>9041,'source'=>'EMS','department'=>'Cashier','record_type'=>'payment_receipt','record_id'=>'CR-2026-9041','description'=>'Tuition Deposit & Registration Receipt (₱14,500.00)','operation'=>'upsert','revision'=>1,'applied'=>true,'status'=>'Clean','created_at'=>now()->subMinutes(20)->toDateTimeString()],
                (object)['id'=>9040,'source'=>'PMS','department'=>'Payroll','record_type'=>'payslip_batch','record_id'=>'PAY-2026-10-B','description'=>'October 2026 Faculty Payroll Summary Register','operation'=>'upsert','revision'=>2,'applied'=>true,'status'=>'Clean','created_at'=>now()->subMinutes(40)->toDateTimeString()],
                (object)['id'=>9039,'source'=>'OAS','department'=>'Admission','record_type'=>'applicant_packet','record_id'=>'PKT-2026-0118','description'=>'Verified Admitted Applicant Packet Batch Release','operation'=>'release','revision'=>1,'applied'=>true,'status'=>'Clean','created_at'=>now()->subHours(1)->toDateTimeString()],
                (object)['id'=>9038,'source'=>'ES','department'=>'Enrollment','record_type'=>'class_roster','record_id'=>'ENR-2026-0338','description'=>'Term 1 Official Enrollee Master List Certification','operation'=>'upsert','revision'=>3,'applied'=>true,'status'=>'Clean','created_at'=>now()->subHours(2)->toDateTimeString()],
            ]);
        }

        $ingestionStream = Access::visibleTo(DB::table('documents'), $user, 'documents.')
            ->join('document_versions', 'documents.id', '=', 'document_versions.document_id')
            ->select('documents.id', 'documents.title', 'documents.source', 'documents.classification', 'document_versions.id as version_id', 'document_versions.number', 'document_versions.filename', 'document_versions.scan_status', 'document_versions.size', 'document_versions.checksum', 'documents.updated_at')
            ->orderByDesc('documents.updated_at')
            ->limit(8)
            ->get();
        if ($ingestionStream->isEmpty()) {
            $ingestionStream = $documents->take(5);
        }

        return view('admin', compact('workspaceCounts', 'source', 'section', 'stats', 'accounts', 'connectors', 'audits', 'documents', 'scans', 'subsystemStatuses', 'applicantQueue', 'departmentTransactions', 'ingestionStream'));
    }

    public function account(ActionRequest $request, ?string $account = null)
    {
        $actor = $request->user();
        Gate::forUser($actor)->authorize('accounts.manage');
        $data = $request->validated();
        $data['source'] = $data['role'] === Role::SuperAdmin->value ? null : Source::normalize($data['source'] ?? null);
        if ($account) {
            $status = app(AccountChangeService::class)->submit($actor, $account, $data);

            return back()->with('status', $status === 'pending' ? 'Account change submitted for Super Admin approval.' : 'Account updated.');
        }
        $service = app(InvitationService::class);
        $id = $service->request($actor, $data);
        if ($actor->isSuperAdmin()) {
            $service->deliver($actor, $id);
        }

        return back()->with('status', $actor->isSuperAdmin() ? 'Invitation email sent.' : 'Staff invitation submitted for Super Admin approval.');
    }

    public function connector(ActionRequest $request)
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('connectors.manage');
        abort_unless(in_array($request->validated('source'), \App\Services\WorkspaceRegistry::codes($user, true), true), 422, 'Choose an active ERP workspace.');
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
