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
            ><button type="button" data-staff-filter="active" class="filter-pill" aria-pressed="false">
                Active ({{ $accounts->where('active', true)->count() }})</button
            ><button type="button" data-staff-filter="inactive" class="filter-pill" aria-pressed="false">
                Inactive ({{ $accounts->where('active', false)->count() }})
            </button>
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
                <option>Restricted</option></select
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
                            <th scope="col" class="sort-header" data-sort-table="0" role="columnheader" tabindex="0" aria-sort="none">
                                Document <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th scope="col" class="sort-header" data-sort-table="1" role="columnheader" tabindex="0" aria-sort="none">
                                Source <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th scope="col" class="sort-header" data-sort-table="2" role="columnheader" tabindex="0" aria-sort="none">
                                Classification <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th scope="col" class="sort-header" data-sort-table="3" role="columnheader" tabindex="0" aria-sort="none">
                                Status <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                            <th scope="col" class="sort-header" data-sort-table="4" role="columnheader" tabindex="0" aria-sort="none">
                                Updated <span class="sort-indicator" aria-hidden="true"></span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($documents as $item)
                            <tr
                                data-source="{{ $item->source }}"
                                data-classification="{{ $item->classification }}"
                                data-doc-status="{{ $item->trashed_at?'Trashed':'Active' }}">
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
    @if ($section==='accounts')
        <div class="modal fade" id="account-new" tabindex="-1" aria-labelledby="account-new-title" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form method="post" action="/admin/accounts">
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
                        <form method="post" action="/admin/accounts/{{ $account->id }}">
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
                    <form method="post" action="/admin/connectors">
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
