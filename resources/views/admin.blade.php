@extends ('layout')
@php $viewer=$viewer??auth()->user(); @endphp
@section ('title','Administration · '.ucfirst($section))
@section ('content')
    @php
    $titles=['overview'=>'A connected campus, at a glance.','repository'=>'School repository','accounts'=>'Staff accounts','connectors'=>'ERP connectors','scans'=>'File security','audit'=>'Audit trail'];
    $subtitles=['overview'=>'Manage the people, documents, and connections across your school.','repository'=>'Browse documents across your authorized workspaces.','accounts'=>'Manage authorized school staff and their workspace access.','connectors'=>'Give each source system its own scoped connection to DMS.','scans'=>'Review file versions that remain blocked by scanning or integrity checks.','audit'=>'Trace sensitive actions to the staff account or source connector responsible.'];
@endphp
    <div class="page-heading">
        <div>
            <span class="eyebrow">{{ $section==='overview'?'DMS ADMINISTRATION':'DMS / '.strtoupper($titles[$section]) }}</span>
            <h1>{{ $titles[$section] }}</h1>
            <p>{{ $subtitles[$section] }}</p>
        </div>
        @if ($section==='accounts')
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#account-new">Invite staff</button>
        @elseif ($section==='connectors')
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#connector-new">Add connector</button>
        @endif
    </div>
    @if ($section==='overview')
        @include ('partials.stats')

        <div class="dept-lens-wrapper mb-4">
            <div class="dept-lens-heading mb-2">
                <span class="eyebrow">ROLE &amp; DEPARTMENT PERSPECTIVE</span>
                <p class="mb-2 text-muted">Select an administrative role lens to filter cross-departmental queues, documents, and transaction workflows.</p>
            </div>
            <div class="filter-pills-bar dept-lens-bar" role="tablist" aria-label="Department Lenses">
                <button type="button" class="filter-pill active" data-dept-lens="all" aria-selected="true">
                    <span class="dept-dot dot-all"></span> Enterprise Admin (All Subsystems)
                </button>
                <button type="button" class="filter-pill" data-dept-lens="OAS" aria-selected="false">
                    <span class="dept-dot dot-oas"></span> Admission (OAS)
                </button>
                <button type="button" class="filter-pill" data-dept-lens="ES" aria-selected="false">
                    <span class="dept-dot dot-es"></span> Registrar &amp; Enrollment (ES)
                </button>
                <button type="button" class="filter-pill" data-dept-lens="EMS" aria-selected="false">
                    <span class="dept-dot dot-ems"></span> Cashier &amp; Staff (EMS)
                </button>
                <button type="button" class="filter-pill" data-dept-lens="PMS" aria-selected="false">
                    <span class="dept-dot dot-pms"></span> Payroll &amp; HR (PMS)
                </button>
                <button type="button" class="filter-pill" data-dept-lens="DMS" aria-selected="false">
                    <span class="dept-dot dot-dms"></span> Central Storage (DMS)
                </button>
            </div>
        </div>

        <section class="panel mb-4 subsystem-status-panel" id="subsystem-matrix-section">
            <div class="panel-header">
                <div>
                    <h2>5 Subsystem Integration Health Matrix</h2>
                    <p class="panel-subtitle">Live health status, synchronization mode, and intake rates across connected school subsystems.</p>
                </div>
                <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">5 Subsystems Integrated</span>
            </div>
            <div class="subsystem-grid">
                @foreach (($subsystemStatuses ?? collect()) as $sub)
                    <div class="subsystem-card" data-subsystem-card="{{ $sub->code }}">
                        <div class="subsystem-card-header">
                            <span class="subsystem-pill subsystem-pill-{{ strtolower($sub->code) }}">{{ $sub->code }}</span>
                            <span class="subsystem-status-indicator {{ strtolower($sub->status) === 'online' ? 'status-online' : 'status-connected' }}">
                                ● {{ $sub->status }}
                            </span>
                        </div>
                        <h3 class="subsystem-card-title">{{ $sub->name }}</h3>
                        <p class="subsystem-dept-label">{{ $sub->department }}</p>
                        <div class="subsystem-card-metrics">
                            <div class="metric-item">
                                <span class="metric-label">Latency</span>
                                <strong class="metric-value">{{ $sub->latency }}ms</strong>
                            </div>
                            <div class="metric-item">
                                <span class="metric-label">Ingested</span>
                                <strong class="metric-value">{{ number_format($sub->total) }}</strong>
                            </div>
                            <div class="metric-item">
                                <span class="metric-label">Active Queue</span>
                                <strong class="metric-value">{{ $sub->queue }}</strong>
                            </div>
                        </div>
                        <div class="subsystem-card-footer">
                            <span class="sync-mode-pill">{{ $sub->sync_mode }}</span>
                            <span class="endpoint-code">{{ $sub->endpoint }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="panel mb-4" id="ingestion-stream-section">
            <div class="panel-header">
                <div>
                    <h2>Centralized Document Ingestion Stream</h2>
                    <p class="panel-subtitle">Real-time client document tracking across all 5 subsystems with ClamAV malware scanning and SHA-256 integrity.</p>
                </div>
                <div class="d-flex gap-2 align-items-center">
                    <span class="text-muted small">Auto-verifying ClamAV</span>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-zebra align-middle mb-0" id="ingestion-stream-table">
                    <thead>
                        <tr>
                            <th scope="col">Subsystem</th>
                            <th scope="col">Document / File</th>
                            <th scope="col">Classification</th>
                            <th scope="col">Security Scan</th>
                            <th scope="col">Version / Checksum</th>
                            <th scope="col">Ingested At</th>
                            <th scope="col" class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse (($ingestionStream ?? collect()) as $item)
                            @php
                                $subCode = $item->source ?? 'DMS';
                                $class = $item->classification ?? 'Internal';
                                $scanStatus = $item->scan_status ?? 'Clean';
                                $isClean = strtolower($scanStatus) === 'clean';
                                $checksumShort = substr($item->checksum ?? 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855', 0, 10);
                                $versionId = $item->version_id ?? $item->id;
                            @endphp
                            <tr data-subsystem-row="{{ $subCode }}" data-classification-row="{{ $class }}">
                                <td>
                                    <span class="subsystem-pill subsystem-pill-{{ strtolower($subCode) }}">{{ $subCode }}</span>
                                </td>
                                <td>
                                    <div class="doc-file-cell">
                                        <a href="/documents/{{ $item->id }}" class="doc-title-link">{{ $item->title }}</a>
                                        <small class="text-muted d-block">{{ $item->filename ?? ($item->title . '.pdf') }} ({{ number_format(($item->size ?? 154820) / 1024, 0) }} KB)</small>
                                    </div>
                                </td>
                                <td>
                                    <span class="classification class-{{ strtolower($class) }}">{{ $class }}</span>
                                </td>
                                <td>
                                    <span class="status-badge {{ strtolower($scanStatus) }}">{{ $scanStatus }}</span>
                                </td>
                                <td>
                                    <div class="checksum-cell">
                                        <strong>v{{ $item->number ?? 1 }}</strong>
                                        <span class="font-monospace text-muted small ms-1" title="SHA-256: {{ $item->checksum ?? '—' }}">SHA:{{ $checksumShort }}…</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-nowrap">{{ Illuminate\Support\Carbon::parse($item->updated_at ?? now())->format('M d, H:i') }}</span>
                                </td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="modal" data-bs-target="#ingestion-preview-modal" data-preview-title="{{ $item->title }}" data-preview-version="{{ $versionId }}" data-preview-clean="{{ $isClean ? 'true' : 'false' }}">
                                        Preview
                                    </button>
                                    @if ($isClean)
                                        <a href="/files/{{ $versionId }}" class="btn btn-sm btn-outline-primary me-1" download>
                                            Download
                                        </a>
                                    @else
                                        <button type="button" class="btn btn-sm btn-outline-danger me-1" disabled title="Download blocked: Scan is {{ $scanStatus }}">
                                            Blocked
                                        </button>
                                    @endif
                                    <button type="button" class="btn btn-sm btn-light border" data-bs-toggle="modal" data-bs-target="#ingestion-provenance-modal" data-prov-id="{{ $item->id }}" data-prov-title="{{ $item->title }}" data-prov-source="{{ $subCode }}" data-prov-class="{{ $class }}" data-prov-checksum="{{ $item->checksum ?? 'a591a6d4...' }}" data-prov-scan="{{ $scanStatus }}">
                                        Provenance
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-4 text-muted">No files ingested yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="row g-4 mb-4 enterprise-queues-grid">
            <div class="col-lg-6" data-dept-section="OAS,ES">
                <section class="panel h-100">
                    <div class="panel-header">
                        <div>
                            <h2>Admission → Enrollment Applicant Queue</h2>
                            <p class="panel-subtitle">Verified applicant packet hand-off from Admission (OAS) to Registrar Enrollment (ES).</p>
                        </div>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">OAS → ES Exchange</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-zebra align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Applicant</th>
                                    <th scope="col">Program &amp; Submitted Files</th>
                                    <th scope="col">Verification</th>
                                    <th scope="col">Packet Release</th>
                                    <th scope="col" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse (($applicantQueue ?? collect()) as $app)
                                    <tr>
                                        <td>
                                            <strong>{{ $app->name }}</strong>
                                            <small class="text-muted d-block font-monospace">{{ $app->applicant_id }}</small>
                                        </td>
                                        <td>
                                            <span>{{ $app->program }}</span>
                                            <small class="text-muted d-block">{{ $app->documents_summary }} ({{ $app->files_count }} files)</small>
                                        </td>
                                        <td>
                                            @if ($app->verified)
                                                <span class="status-badge clean">Verified</span>
                                            @else
                                                <span class="status-badge pending">Pending</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if ($app->released)
                                                <span class="badge bg-success text-white px-2 py-1">Released to ES</span>
                                            @else
                                                <span class="badge bg-secondary text-white px-2 py-1">Held in OAS</span>
                                            @endif
                                        </td>
                                        <td class="text-end text-nowrap">
                                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#applicant-detail-modal" data-applicant-id="{{ $app->applicant_id }}" data-applicant-name="{{ $app->name }}" data-applicant-program="{{ $app->program }}" data-applicant-summary="{{ $app->documents_summary }}" data-applicant-files="{{ $app->files_count }}" data-applicant-verified="{{ $app->verified ? '1' : '0' }}" data-applicant-released="{{ $app->released ? '1' : '0' }}">
                                                Inspect →
                                            </button>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-3 text-muted">No applicant packets in queue.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="col-lg-6" data-dept-section="EMS,PMS">
                <section class="panel h-100">
                    <div class="panel-header">
                        <div>
                            <h2>Cashier &amp; Payroll Transaction Overview</h2>
                            <p class="panel-subtitle">Audited financial vouchers, cashier tuition slips, and compensation registers.</p>
                        </div>
                        <a href="/admin/audit" class="text-link">Full ledger →</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-striped table-zebra align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">Source</th>
                                    <th scope="col">Transaction / Voucher</th>
                                    <th scope="col">Operation</th>
                                    <th scope="col">Recorded</th>
                                    <th scope="col" class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse (($departmentTransactions ?? collect()) as $trx)
                                    <tr>
                                        <td>
                                            <span class="subsystem-pill subsystem-pill-{{ strtolower($trx->source) }}">{{ $trx->source }}</span>
                                        </td>
                                        <td>
                                            <strong>{{ $trx->record_id }}</strong>
                                            <small class="text-muted d-block">{{ $trx->description ?? ($trx->record_type . ' record') }}</small>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border px-2 py-1">{{ strtoupper($trx->operation) }} v{{ $trx->revision }}</span>
                                        </td>
                                        <td>
                                            <span class="text-nowrap small text-muted">{{ Illuminate\Support\Carbon::parse($trx->created_at)->format('M d, H:i') }}</span>
                                        </td>
                                        <td class="text-end text-nowrap">
                                            @if ($trx->source === 'EMS')
                                                <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#cashier-verify-modal" data-assessment-id="{{ $trx->record_id }}" data-student-name="{{ $trx->description ?? 'Cashier Tuition Payment' }}" data-amount="14500.00">
                                                    Verify OR →
                                                </button>
                                            @elseif ($trx->source === 'PMS')
                                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#payroll-detail-modal" data-payroll-id="{{ $trx->record_id }}" data-employee="{{ $trx->description ?? 'Faculty Compensation' }}" data-amount="45250.00" data-status="{{ $trx->operation ?? 'upsert' }}">
                                                    Voucher →
                                                </button>
                                            @else
                                                <span class="text-muted small">Recorded</span>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-3 text-muted">No departmental transactions logged.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>

        <div class="section-heading">
            <h2>Your connected workspaces</h2>
            <span>{{ $viewer->isSuperAdmin()?'Manage repositories within your school and campus.':'Manage your assigned repository.' }}</span>
        </div>
        <div class="workspace-grid">
            @foreach (($workspaceLabels ?? App\Services\WorkspaceRegistry::labels($viewer)) as $code=>$name)
                <a class="workspace-card" href="/workspaces/{{ strtolower($code) }}"
                    ><span class="source-icon">{{ $code }}</span><span class="workspace-arrow"><x-figma-icon name="arrow-card" /></span>
                    <h3>{{ $name }}</h3>
                    <p>{{ number_format($workspaceCounts[$code]??0) }} documents</p>
                    <span class="workspace-card-link">Open workspace →</span></a
                >
            @endforeach
        </div>
        <div class="admin-bottom">
            <section class="panel">
                <div class="panel-header">
                    <h2>Recent activity</h2>
                    <a href="/admin/audit" class="text-link">View audit trail →</a>
                </div>
                <div class="activity-list">
                    @forelse ($audits->take(4) as $event)
                        <article class="activity-item">
                            <span class="activity-marker"><x-figma-icon name="check" :size="16" /></span>
                            <div>
                                <strong>{{ ucfirst(str_replace(['.','_'],' ',$event->action)) }}</strong
                                ><small
                                    >{{ $event->actor_name??('Staff #'.$event->actor_id) }} · {{ $event->source??'—' }} · {{ Illuminate\Support\Carbon::parse($event->created_at)->format('M d, H:i') }}</small
                                >
                            </div>
                        </article>
                    @empty
                        <p class="text-muted">No activity yet.</p>
                    @endforelse
                </div>
            </section>
            <section class="panel connection-summary">
                <span class="eyebrow">SYSTEM CONNECTIONS</span>
                <h2>{{ $connectors->where('active',true)->count()===4?'Every source, in sync.':'Your systems, connected.' }}</h2>
                <p>Connect your ERP systems to receive documents and immutable history.</p>
                @foreach (($workspaceLabels ?? App\Services\WorkspaceRegistry::labels($viewer)) as $code=>$name)
                    @php $connected=$connectors->where('source',$code)->where('active',true)->isNotEmpty(); @endphp
                    <div class="connection-line"><span>{{ $code }}</span><span>{{ $connected?'● Connected':'○ Not connected' }}</span></div>
                @endforeach
            </section>
        </div>
        @if ($viewer->can('connectors.manage'))
            <a class="btn btn-outline-secondary mt-4" href="/admin/connectors">Manage ERP connections</a>
        @endif
    @elseif ($section==='accounts')
        <nav class="library-tabs" aria-label="People management">
            <a href="/admin/accounts" class="active" aria-current="page">Staff directory</a>
            @if ($viewer->isSuperAdmin() || $viewer->isAdmin())
                <a href="/admin/invitations">Invitations</a>
                <a href="/admin/account-requests">Account requests</a>
            @endif
        </nav>
        <div class="filter-pills-bar mb-3">
            <button type="button" data-staff-filter="all" class="filter-pill active" aria-pressed="true">All staff ({{ $accounts->count() }})</button
            ><button type="button" data-staff-filter="active" class="filter-pill" aria-pressed="false">Active ({{ $accounts->where('active', true)->count() }})</button
            ><button type="button" data-staff-filter="inactive" class="filter-pill" aria-pressed="false">Inactive ({{ $accounts->where('active', false)->count() }})</button>
        </div>
        <form class="document-toolbar" data-search-form>
            <div class="search-field">
                <x-figma-icon name="search" /><label class="visually-hidden" for="staff-search">Search staff</label
                ><input type="search" id="staff-search" placeholder="Search by name or email…" maxlength="200" />
            </div>
        </form>
        <section class="panel">
            <div class="panel-header"><h2>School staff</h2></div>
            <div class="table-responsive">
                <table class="table table-striped table-zebra" data-filter-table id="staff-table">
                    <thead>
                        <tr>
                            <th>Staff member</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($accounts as $person)
                            <tr data-staff-status="{{ $person->active?'active':'inactive' }}">
                                <td><strong>{{ $person->name }}</strong></td>
                                <td>{{ $person->email }}</td>
                                <td>{{ config('dms.role_labels.'.$person->role, ucfirst(str_replace('_',' ',$person->role))) }}</td>
                                <td>{{ $person->role==='super_admin'||!$person->source?'All sources':$person->source }}</td>
                                <td>
                                    <span class="status-badge {{ $person->active?'clean':'neutral' }}">{{ $person->active?'Active':'Inactive' }}</span>
                                </td>
                                <td>
                                    <button type="button" class="text-button" data-bs-toggle="modal" data-bs-target="#account-{{ $person->id }}">Edit →</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <div class="info-strip mt-4">
            <x-figma-icon name="link-notice" :size="24" />
            <div>
                <strong>Accounts are provisioned via expiring invitations</strong>
                <p>Recipients choose their own password. Scoped Admin proposals require Super Admin approval before dispatch.</p>
            </div>
        </div>
    @elseif ($section==='connectors')
        @if (session('connector_token'))
            <div class="alert alert-warning" role="status">
                <strong>Copy this token now. It will not be displayed again.</strong
                ><input class="form-control mt-2 font-monospace" readonly aria-label="New connector token" value="{{ session('connector_token') }}" /><button
                    type="button"
                    class="btn btn-sm btn-outline-secondary mt-2"
                    data-copy-token>
                    Copy token
                </button>
            </div>
        @endif
        <div class="info-strip">
            <x-figma-icon name="link-notice" :size="24" />
            <div>
                <strong>A single integration boundary</strong>
                <p>All ERP traffic uses /api/v1. Tokens are bound to one source, school and campus. No shared ERP databases.</p>
            </div>
        </div>
        <section class="panel">
            <div class="panel-header"><h2>Connector identities</h2></div>
            <div class="table-responsive">
                <table class="table table-striped table-zebra">
                    <thead>
                        <tr>
                            <th>Connection</th>
                            <th>Source</th>
                            <th>Status</th>
                            <th>Last request</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($connectors as $connector)
                            <tr>
                                <td><strong>{{ $connector->name }}</strong></td>
                                <td>{{ $connector->source }}</td>
                                <td>
                                    <span
                                        class="status-badge {{ $connector->active?'clean':'neutral' }}"
                                        >{{ $connector->active?'Connected':'Not connected' }}</span
                                    >
                                </td>
                                <td>
                                    {{ $connector->last_seen_at?Illuminate\Support\Carbon::parse($connector->last_seen_at)->format('M d, H:i'):'Not connected yet' }}
                                </td>
                                <td>
                                    @if ($connector->active)
                                        <form
                                            method="post"
                                            action="/admin/connectors/{{ $connector->id }}"
                                            data-demo-action="revoke-connector"
                                            data-confirm="Revoke this connector? Its ERP will no longer be able to authenticate.">
                                            @csrf
                                            @method ('DELETE')
                                            <button type="submit" class="text-button">Revoke token →</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr class="is-empty">
                                <td colspan="5">
                                    <div class="empty-state">
                                        <h3>No ERP connectors yet</h3>
                                        <p>Add a scoped connector for each source.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section class="panel evidence-panel">
            <h2>Ready for your ERP team</h2>
            <p>Bearer token authentication · 120 requests per minute per connector</p>
            <p>Safe retries return the original receipt. Conflicting writes return 409.</p>
            <p class="section-description">New tokens are shown once. Keep them in the source ERP’s secure configuration.</p>
        </section>
    @elseif ($section==='repository')
        <form class="document-toolbar" method="get" data-search-form>
            <div class="search-field">
                <x-figma-icon name="search" /><label class="visually-hidden" for="repository-search">Search documents</label
                ><input type="search" id="repository-search" name="q" value="{{ request('q') }}" placeholder="Search documents or reference…" maxlength="200" />
            </div>
            <label class="visually-hidden" for="repository-source">Source system</label
            ><select id="repository-source" name="source" class="form-select filter-select">
                <option value="">All workspaces</option>
                @foreach (($workspaceLabels ?? App\Services\WorkspaceRegistry::labels($viewer)) as $code=>$label)
                    <option value="{{ $code }}" @selected (request('source')===$code)>{{ $code }}</option>
                @endforeach</select
            ><label class="visually-hidden" for="repository-class">Classification</label
            ><select id="repository-class" name="classification" class="form-select filter-select">
                <option value="">All classifications</option>
                <option>Internal</option>
                <option>Confidential</option>
                <option>Restricted</option>
            </select
            ><label class="visually-hidden" for="repository-status">Status</label
            ><select id="repository-status" name="status" class="form-select filter-select">
                <option value="">All statuses</option>
                <option value="Active">Active</option>
                <option value="Trashed">Trashed</option>
            </select>
        </form>
        <section class="panel">
            <div class="panel-header"><h2>School documents</h2></div>
            <div class="table-responsive">
                <table class="table table-zebra table-sticky table-striped" data-filter-table>
                    <thead>
                        <tr>
                            <th scope="col" class="sort-header" data-sort-table="0" role="columnheader" tabindex="0" aria-sort="none">Document <span class="sort-indicator" aria-hidden="true"></span></th>
                            <th scope="col" class="sort-header" data-sort-table="1" role="columnheader" tabindex="0" aria-sort="none">Source <span class="sort-indicator" aria-hidden="true"></span></th>
                            <th scope="col" class="sort-header" data-sort-table="2" role="columnheader" tabindex="0" aria-sort="none">Classification <span class="sort-indicator" aria-hidden="true"></span></th>
                            <th scope="col" class="sort-header" data-sort-table="3" role="columnheader" tabindex="0" aria-sort="none">Status <span class="sort-indicator" aria-hidden="true"></span></th>
                            <th scope="col" class="sort-header" data-sort-table="4" role="columnheader" tabindex="0" aria-sort="none">Updated <span class="sort-indicator" aria-hidden="true"></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($documents as $item)
                            <tr data-source="{{ $item->source }}" data-classification="{{ $item->classification }}" data-doc-status="{{ $item->trashed_at?'Trashed':'Active' }}">
                                <td>
                                    <a class="document-title" href="/documents/{{ $item->id }}">{{ $item->title }}</a>
                                </td>
                                <td>{{ $item->source }}</td>
                                <td><span class="classification class-{{ strtolower($item->classification) }}">{{ $item->classification }}</span></td>
                                <td><span class="status-badge {{ $item->trashed_at?'neutral':'clean' }}">{{ $item->trashed_at?'Trashed':'Active' }}</span></td>
                                <td>{{ Illuminate\Support\Carbon::parse($item->updated_at)->format('M d, Y') }}</td>
                            </tr>
                        @empty
                            <tr class="is-empty">
                                <td colspan="5" class="py-5 text-center">No documents found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($documents->hasPages())
                <div class="panel-pagination">{{ $documents->links('pagination::bootstrap-5') }}</div>
            @endif
        </section>
    @elseif ($section==='scans')
        <div class="info-strip">
            <x-figma-icon name="shield-warning" :size="24" />
            <div>
                <strong>Scanning runs automatically in the background</strong>
                <p>Staff never need to start a scan. Pending, failed and quarantined versions stay blocked from download.</p>
            </div>
        </div>
        <section class="panel">
            <div class="panel-header"><h2>Versions awaiting attention</h2></div>
            <div class="table-responsive">
                <table class="table table-striped table-zebra">
                    <thead>
                        <tr>
                            <th>Document</th>
                            <th>Source</th>
                            <th>Version</th>
                            <th>Scan status</th>
                            <th>Received</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($scans as $scan)
                            <tr>
                                <td>
                                    <a class="document-title" href="/documents/{{ $scan->document_id }}">{{ $scan->title }}</a
                                    ><small>{{ $scan->filename }}</small>
                                </td>
                                <td>{{ $scan->source }}</td>
                                <td>v{{ $scan->number }}</td>
                                <td>
                                    <span class="status-badge {{ strtolower($scan->scan_status) }}">{{ $scan->scan_status }}</span>
                                    @if ($scan->scan_status==='Failed')
                                        <small>Automatic retry pending</small>
                                    @endif
                                </td>
                                <td>{{ Illuminate\Support\Carbon::parse($scan->created_at)->format('M d · H:i') }}</td>
                            </tr>
                        @empty
                            <tr class="is-empty">
                                <td colspan="5">
                                    <div class="empty-state">
                                        <h3>No files awaiting attention</h3>
                                        <p>Background scan results will appear here.</p>
                                    </div>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <p class="security-note mt-4">Scanning uses local ClamAV. A clean scan is a security result, not a business approval.</p>
    @elseif ($section==='audit')
        <form class="document-toolbar" data-search-form>
            <div class="search-field">
                <x-figma-icon name="search" /><label class="visually-hidden" for="audit-search">Search audit actions</label
                ><input type="search" id="audit-search" name="q" value="{{ request('q') }}" placeholder="Search action, actor or document…" maxlength="200" />
            </div>
            <label class="visually-hidden" for="audit-source">Source system</label
            ><select class="form-select filter-select" id="audit-source" name="source">
                <option value="">All sources</option>
                @foreach (($workspaceLabels ?? App\Services\WorkspaceRegistry::labels($viewer)) as $code=>$name)
                    <option>{{ $code }}</option>
                @endforeach
            </select>
        </form>
        <section class="panel">
            <div class="panel-header"><h2>Repository activity</h2></div>
            <div class="table-responsive table-scroll-y">
                <table class="table table-zebra table-sticky table-striped" data-filter-table>
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Actor</th>
                            <th>Source</th>
                            <th>Recorded at</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($audits as $event)
                            <tr data-source="{{ $event->source }}">
                                <td><strong>{{ $event->action }}</strong></td>
                                <td>
                                    {{ $event->actor_name??($event->actor_id?'Staff #'.$event->actor_id:($event->connector_id?'Connector #'.$event->connector_id:'System')) }}
                                </td>
                                <td>{{ $event->source??'—' }}</td>
                                <td class="text-nowrap">{{ Illuminate\Support\Carbon::parse($event->created_at)->format('M d, Y · H:i') }}</td>
                                <td>
                                    <button
                                        type="button"
                                        class="text-button"
                                        data-bs-toggle="modal"
                                        data-bs-target="#payload-modal"
                                        data-payload="{{ $event->details }}"
                                        data-record="{{ $event->action }}"
                                        data-revision="">
                                        View details →
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr class="is-empty">
                                <td colspan="5" class="py-5 text-center">No audit entries yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($audits->hasPages())
                <div class="panel-pagination">{{ $audits->links('pagination::bootstrap-5') }}</div>
            @endif
        </section>
    @endif
@endsection
@push ('dialogs')
    @if ($section==='overview')
        <div class="modal fade" id="ingestion-provenance-modal" tabindex="-1" aria-labelledby="ingestion-prov-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="ingestion-prov-title">Document Ingestion Provenance</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="modal-subtitle">Verified immutable transmission and custody evidence.</p>
                        <div class="provenance-detail-card mb-3 p-3 bg-light rounded border">
                            <div class="mb-2"><strong>Document Title:</strong> <span id="prov-doc-title">—</span></div>
                            <div class="mb-2"><strong>Subsystem Origin:</strong> <span id="prov-doc-source" class="subsystem-pill">—</span></div>
                            <div class="mb-2"><strong>Classification Level:</strong> <span id="prov-doc-class" class="classification">—</span></div>
                            <div class="mb-2"><strong>ClamAV Scan Result:</strong> <span id="prov-doc-scan" class="status-badge">—</span></div>
                            <div class="mb-2"><strong>SHA-256 Checksum:</strong> <div id="prov-doc-checksum" class="font-monospace small text-break">—</div></div>
                            <div><strong>Idempotency Receipt:</strong> <span class="badge bg-success-subtle text-success">Verified &amp; Committed</span></div>
                        </div>
                        <div class="info-strip">
                            <x-figma-icon name="shield-small" :size="24" />
                            <div>
                                <strong>Custody &amp; Non-Repudiation Policy</strong>
                                <p>Original document bytes are encrypted and stored in private storage. Versions are immutable and cannot be overwritten.</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Close Inspector</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="ingestion-preview-modal" tabindex="-1" aria-labelledby="ingestion-prev-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="ingestion-prev-title">Document Ingestion Preview</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body p-0">
                        <div id="ingestion-preview-container" class="p-3 text-center" style="min-height: 400px; background: #f8fafc;">
                            <div id="preview-blocked-notice" class="alert alert-warning m-3 text-start" hidden>
                                <strong>File Download / Preview Blocked</strong>
                                <p class="mb-0">This document version has not cleared malware scanning or is currently flagged. Only Clean verified documents may be viewed.</p>
                            </div>
                            <iframe id="ingestion-preview-frame" style="width: 100%; height: 500px; border: none; border-radius: 4px;" title="Document Preview"></iframe>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close Preview</button>
                        <a id="ingestion-preview-download-btn" href="#" class="btn btn-primary" download>Download Clean File</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="applicant-detail-modal" tabindex="-1" aria-labelledby="applicant-modal-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="applicant-modal-title">Applicant Packet Inspection</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="modal-subtitle">Review verified admissions requirements and release student packet to Enrollment (ES).</p>
                        <div class="p-3 mb-3 bg-light rounded border">
                            <div class="mb-2"><strong>Applicant ID:</strong> <span id="applicant-detail-id" class="font-monospace">—</span></div>
                            <div class="mb-2"><strong>Applicant Name:</strong> <span id="applicant-detail-name">—</span></div>
                            <div class="mb-2"><strong>Target Program:</strong> <span id="applicant-detail-program">—</span></div>
                            <div class="mb-2"><strong>Submitted Documents:</strong> <span id="applicant-detail-summary">—</span></div>
                            <div class="mb-2"><strong>Verification Status:</strong> <span id="applicant-detail-status" class="status-badge clean">—</span></div>
                            <div><strong>Packet Release:</strong> <span id="applicant-detail-release-status" class="badge bg-secondary">—</span></div>
                        </div>
                        <div class="info-strip">
                            <x-figma-icon name="link-notice" :size="24" />
                            <div>
                                <strong>Inter-Subsystem Transfer (OAS → ES)</strong>
                                <p>Releasing applicant verification allows Registrar Enrollment to allocate subjects, generate student numbers, and issue Certificates of Registration.</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-primary" id="btn-applicant-enroll">Transition to Enrollment (ES) →</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="cashier-verify-modal" tabindex="-1" aria-labelledby="cashier-verify-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form id="cashier-verify-form" data-demo-action="cashier-verify">
                        <div class="modal-header">
                            <h2 class="modal-title" id="cashier-verify-title">Verify Cashier Payment</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="modal-subtitle">Record official receipt details and mark tuition/fee assessment as paid.</p>
                            <div class="mb-3">
                                <label for="cashier-verify-assessment-id" class="form-label">Assessment / Voucher ID</label>
                                <input type="text" id="cashier-verify-assessment-id" class="form-control font-monospace" readonly />
                            </div>
                            <div class="mb-3">
                                <label for="cashier-verify-student" class="form-label">Account / Student Details</label>
                                <input type="text" id="cashier-verify-student" class="form-control" readonly />
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-sm-6">
                                    <label for="cashier-verify-amount" class="form-label">Amount Due (PHP)</label>
                                    <input type="number" step="0.01" id="cashier-verify-amount" class="form-control" required />
                                </div>
                                <div class="col-sm-6">
                                    <label for="cashier-verify-method" class="form-label">Payment Method</label>
                                    <select id="cashier-verify-method" class="form-select">
                                        <option value="Cash">Cash (Teller)</option>
                                        <option value="Online">GCash / Maya / Online</option>
                                        <option value="Bank">Bank Deposit / Transfer</option>
                                        <option value="Check">Manager's Check</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label for="cashier-verify-or-number" class="form-label">Official Receipt (OR) Number</label>
                                <input type="text" id="cashier-verify-or-number" class="form-control font-monospace" placeholder="OR-2026-XXXX" required maxlength="50" />
                            </div>
                            <div class="info-strip">
                                <x-figma-icon name="shield-small" :size="24" />
                                <div>
                                    <strong>Immutable Cashier Ledger</strong>
                                    <p>Upon verification, an immutable receipt voucher is linked to the student assessment and indexed into the central document repository.</p>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="btn-cashier-confirm">Confirm &amp; Verify Payment</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="modal fade" id="payroll-detail-modal" tabindex="-1" aria-labelledby="payroll-detail-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title" id="payroll-detail-title">Payroll Voucher Breakdown</h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="modal-subtitle">Audited payroll register, statutory deductions, and disbursement status.</p>
                        <div class="p-3 mb-3 bg-light rounded border">
                            <div class="mb-2"><strong>Payroll Voucher:</strong> <span id="payroll-detail-id" class="font-monospace">—</span></div>
                            <div class="mb-2"><strong>Department / Description:</strong> <span id="payroll-detail-employee">—</span></div>
                            <div class="mb-2"><strong>Total Gross Amount:</strong> <span id="payroll-detail-gross" class="fw-bold">—</span></div>
                            <div class="mb-2"><strong>Disbursement Status:</strong> <span id="payroll-detail-status" class="status-badge clean">—</span></div>
                            <div><strong>Statutory Remittances:</strong> <span class="badge bg-success-subtle text-success">GSIS, PhilHealth, Pag-IBIG Deducted</span></div>
                        </div>
                        <div class="info-strip">
                            <x-figma-icon name="shield-warning" :size="24" />
                            <div>
                                <strong>Confidential Compensation Data</strong>
                                <p>Access to individual compensation items and batch payroll registers is restricted to authorized HR and Payroll accounting officers.</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="button" class="btn btn-primary" id="btn-payroll-disburse">Mark as Disbursed</button>
                    </div>
                </div>
            </div>
        </div>
    @elseif ($section==='accounts')
        <div class="modal fade" id="account-new" tabindex="-1" aria-labelledby="account-new-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="post" action="/admin/accounts" data-demo-action="account">
                        <div class="modal-header">
                            <h2 id="account-new-title" class="modal-title">Invite staff member</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="modal-subtitle">An expiring, single-use invitation will be sent for the recipient to activate their account. Access is scoped to {{ $viewer->school_id }} · {{ $viewer->campus }} campus.</p>
                            @include ('partials.account-form',['account'=>null])
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button
                            ><button type="submit" class="btn btn-primary">Send invitation</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        @foreach ($accounts as $account)
            <div class="modal fade" id="account-{{ $account->id }}" tabindex="-1" aria-labelledby="account-title-{{ $account->id }}" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <form method="post" action="/admin/accounts/{{ $account->id }}" data-demo-action="account-edit">
                            <div class="modal-header">
                                <h2 class="modal-title" id="account-title-{{ $account->id }}">Edit staff account</h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                @include ('partials.account-form',['account'=>$account])
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button
                                ><button type="submit" class="btn btn-primary">Save account</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    @elseif ($section==='connectors')
        <div class="modal fade" id="connector-new" tabindex="-1" aria-labelledby="connector-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <form method="post" action="/admin/connectors" data-demo-action="connector">
                        @csrf
                        <div class="modal-header">
                            <h2 class="modal-title" id="connector-title">Add ERP connector</h2>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="modal-subtitle">Create a scoped connection for one source system.</p>
                            <label for="connector-name" class="form-label">Connection name</label
                            ><input
                                id="connector-name"
                                name="name"
                                class="form-control mb-4"
                                maxlength="100"
                                required
                                placeholder="e.g. EMS · Main campus" /><label for="connector-source" class="form-label">Source system</label
                            ><select id="connector-source" name="source" class="form-select mb-4">
                                @foreach (($workspaceLabels ?? App\Services\WorkspaceRegistry::labels($viewer)) as $code=>$label)
                                    <option value="{{ $code }}">{{ $code }} — {{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="permission-note">
                                <strong>The token is shown only once.</strong>
                                <p>Copy it into the source ERP’s secure configuration.<br />School and campus are inherited from your account.</p>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button
                            ><button type="submit" class="btn btn-primary">Generate token</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @elseif ($section==='audit')
        @include ('partials.payload-modal')
    @endif
@endpush
