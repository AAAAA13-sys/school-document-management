@extends ('layout')
@php
    $viewer = $viewer ?? auth()->user();
    $section = 'workspaces';
    $activeCount = $workspaces->where('status', 'active')->count();
    $erpCount = $workspaces->where('kind', 'erp')->count();
    $internalCount = $workspaces->where('kind', 'internal')->count();
    $totalMembers = $memberships->flatten(1)->count();
@endphp
@section ('title', 'Administration · Workspace Registry')
@section ('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">DMS / WORKSPACES</span>
            <h1>Workspace Registry</h1>
            <p>Manage registered campus workspaces, ERP integrations, repository lifecycles, and staff memberships.</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modal-add-workspace">
            + Add workspace
        </button>
    </div>

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <strong>Success:</strong> {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Action failed:</strong> {{ $errors->first() }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <div class="stats-grid mb-4">
        <div class="stat-card">
            <span class="stat-label">Total Workspaces</span>
            <strong class="stat-value">{{ $workspaces->count() }}</strong>
            <span class="stat-subtext">{{ $activeCount }} currently active</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">ERP Connectors</span>
            <strong class="stat-value">{{ $erpCount }}</strong>
            <span class="stat-subtext">Integrated source systems</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Internal Workspaces</span>
            <strong class="stat-value">{{ $internalCount }}</strong>
            <span class="stat-subtext">Department repositories</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Assigned Members</span>
            <strong class="stat-value">{{ $totalMembers }}</strong>
            <span class="stat-subtext">Active account grants</span>
        </div>
    </div>

    <section class="panel mb-4">
        <div class="panel-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2>Registered Workspaces ({{ $workspaces->count() }})</h2>
                <span class="panel-note">Stable codes identify storage repositories. Archived workspaces block standard user access.</span>
            </div>
            <div class="d-flex align-items-center gap-2">
                <input
                    type="search"
                    id="workspace-filter"
                    class="form-control form-control-sm"
                    placeholder="Search by code or name…"
                    style="max-width: 240px"
                    oninput="filterWorkspaces(this.value)" />
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-zebra align-middle mb-0" id="workspaces-table">
                <thead>
                    <tr>
                        <th style="width: 120px">Code</th>
                        <th>Workspace Name</th>
                        <th style="width: 110px">Kind</th>
                        <th style="width: 120px">Lifecycle</th>
                        <th style="width: 150px">Memberships</th>
                        <th style="width: 220px" class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($workspaces as $workspace)
                        @php
                            $wsMembers = $memberships[$workspace->id] ?? collect();
                            $memberCount = $wsMembers->count();
                            $memberUserIds = $wsMembers->pluck('user_id')->all();
                            $assignedUsers = $users->whereIn('id', $memberUserIds);
                        @endphp
                        <tr class="workspace-row" data-code="{{ strtolower($workspace->code) }}" data-name="{{ strtolower($workspace->name) }}">
                            <td>
                                <strong class="badge bg-dark font-monospace text-uppercase px-2 py-1" style="font-size: 13px; letter-spacing: 0.5px">
                                    {{ $workspace->code }}
                                </strong>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark">{{ $workspace->name }}</div>
                                <small class="text-muted">Repository ID: #{{ $workspace->id }}</small>
                            </td>
                            <td>
                                <span class="badge {{ $workspace->kind === 'erp' ? 'bg-primary' : 'bg-secondary' }} px-2 py-1 text-uppercase" style="font-size: 11px">
                                    {{ $workspace->kind }}
                                </span>
                            </td>
                            <td>
                                <span class="status-badge {{ $workspace->status === 'active' ? 'clean' : 'failed' }}">
                                    ● {{ ucfirst($workspace->status) }}
                                </span>
                            </td>
                            <td>
                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                                    data-bs-toggle="modal"
                                    data-bs-target="#modal-members-{{ $workspace->id }}"
                                    title="View and manage members">
                                    <x-figma-icon name="users" :size="14" />
                                    <span>{{ $memberCount }} {{ Str::plural('member', $memberCount) }}</span>
                                </button>
                            </td>
                            <td class="text-end">
                                <div class="btn-group btn-group-sm">
                                    @if ($workspace->status === 'active')
                                        <a href="/workspaces/{{ strtolower($workspace->code) }}" class="btn btn-outline-primary" title="Open workspace">
                                            Open →
                                        </a>
                                    @endif
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#modal-edit-{{ $workspace->id }}">
                                        Edit
                                    </button>
                                </div>
                            </td>
                        </tr>

                        {{-- Modal: Edit Workspace --}}
                        <div class="modal fade" id="modal-edit-{{ $workspace->id }}" tabindex="-1" aria-labelledby="label-edit-{{ $workspace->id }}" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <form method="post" action="{{ route('workspaces.registry.update', $workspace->id) }}" class="modal-content">
                                    @csrf
                                    @method ('PATCH')
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="label-edit-{{ $workspace->id }}">Edit Workspace: {{ $workspace->code }}</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label for="name-{{ $workspace->id }}" class="form-label fw-semibold">Workspace Name</label>
                                            <input id="name-{{ $workspace->id }}" name="name" value="{{ $workspace->name }}" required maxlength="150" class="form-control" />
                                        </div>
                                        <div class="mb-3">
                                            <label for="status-{{ $workspace->id }}" class="form-label fw-semibold">Lifecycle Status</label>
                                            <select id="status-{{ $workspace->id }}" name="status" class="form-select">
                                                <option value="active" @selected($workspace->status === 'active')>Active (Accepts documents and workspace access)</option>
                                                <option value="archived" @selected($workspace->status === 'archived')>Archived (Read-only / Blocks active operations)</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        {{-- Modal: Manage Memberships --}}
                        <div class="modal fade" id="modal-members-{{ $workspace->id }}" tabindex="-1" aria-labelledby="label-members-{{ $workspace->id }}" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered modal-lg">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title" id="label-members-{{ $workspace->id }}">Members of {{ $workspace->name }} ({{ $workspace->code }})</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-4 p-3 bg-light rounded border">
                                            <h6 class="fw-semibold mb-2">Assign Account Membership</h6>
                                            <form method="post" action="{{ route('workspaces.registry.membership', $workspace->id) }}" class="d-flex gap-2">
                                                @csrf
                                                <label for="member-select-{{ $workspace->id }}" class="visually-hidden">Account</label>
                                                <select id="member-select-{{ $workspace->id }}" name="user_id" class="form-select" required>
                                                    <option value="">Choose an active staff member…</option>
                                                    @foreach ($users as $user)
                                                        @continue (in_array($user->id, $memberUserIds, true))
                                                        <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }}) · {{ ucfirst($user->role) }}</option>
                                                    @endforeach
                                                </select>
                                                <button name="action" value="grant" class="btn btn-primary text-nowrap">Grant Membership</button>
                                            </form>
                                        </div>

                                        <h6 class="fw-semibold mb-2">Current Members ({{ $memberCount }})</h6>
                                        @if ($assignedUsers->isEmpty())
                                            <p class="text-muted fst-italic">No accounts explicitly assigned to this workspace yet.</p>
                                        @else
                                            <div class="list-group">
                                                @foreach ($assignedUsers as $memberUser)
                                                    <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                                                        <div>
                                                            <div class="fw-semibold">{{ $memberUser->name }}</div>
                                                            <small class="text-muted">{{ $memberUser->email }} · {{ ucfirst($memberUser->role) }}</small>
                                                        </div>
                                                        <form method="post" action="{{ route('workspaces.registry.membership', $workspace->id) }}">
                                                            @csrf
                                                            <input type="hidden" name="user_id" value="{{ $memberUser->id }}" />
                                                            <button name="action" value="revoke" class="btn btn-sm btn-outline-danger" onclick="return confirm('Revoke membership for {{ $memberUser->name }}?')">
                                                                Revoke
                                                            </button>
                                                        </form>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center py-4 text-muted">
                                No registered workspaces found. Create one to get started.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Modal: Add Workspace --}}
    <div class="modal fade" id="modal-add-workspace" tabindex="-1" aria-labelledby="label-add-workspace" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form method="post" action="{{ route('workspaces.registry.store') }}" class="modal-content">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="label-add-workspace">Register New Workspace</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="workspace-code" class="form-label fw-semibold">Stable Code</label>
                        <input
                            id="workspace-code"
                            name="code"
                            required
                            maxlength="64"
                            pattern="[A-Za-z][A-Za-z0-9_-]{0,63}"
                            placeholder="e.g. REGISTRAR, HR, LIBRARY"
                            class="form-control font-monospace text-uppercase" />
                        <small class="text-muted">Must begin with a letter. Used in URLs, storage paths, and connector contracts.</small>
                    </div>
                    <div class="mb-3">
                        <label for="workspace-name" class="form-label fw-semibold">Friendly Display Name</label>
                        <input
                            id="workspace-name"
                            name="name"
                            required
                            maxlength="150"
                            placeholder="e.g. University Registrar Records"
                            class="form-control" />
                    </div>
                    <div class="mb-3">
                        <label for="workspace-kind" class="form-label fw-semibold">Workspace Kind</label>
                        <select id="workspace-kind" name="kind" class="form-select">
                            <option value="internal">Internal (Document repository for school departments)</option>
                            <option value="erp">ERP (Connected to external ERP systems via API)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Workspace</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function filterWorkspaces(q) {
            var search = (q || '').toLowerCase().trim();
            var rows = document.querySelectorAll('.workspace-row');
            rows.forEach(function (row) {
                var code = row.getAttribute('data-code') || '';
                var name = row.getAttribute('data-name') || '';
                if (!search || code.indexOf(search) !== -1 || name.indexOf(search) !== -1) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
    </script>
@endsection
