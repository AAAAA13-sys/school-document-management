@extends ('layout')
@php
    $viewer = $viewer ?? auth()->user();
    $section = 'approvals';
    $pendingCount = $changes->where('status', 'pending')->count();
    $approvedCount = $changes->where('status', 'approved')->count();
    $rejectedCount = $changes->where('status', 'rejected')->count();
@endphp
@section ('title', 'Sensitive Change Requests & Approvals')
@section ('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">DMS / GOVERNANCE</span>
            <h1>Sensitive Change Requests</h1>
            <p>Live documents remain unchanged until approval. Decisions require a reason and cannot be made by the requester.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <strong>Decision recorded:</strong> {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Error:</strong> {{ $errors->first() }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <div class="stats-grid mb-4">
        <div class="stat-card">
            <span class="stat-label">Pending Review</span>
            <strong class="stat-value text-warning">{{ $pendingCount }}</strong>
            <span class="stat-subtext">Awaiting authorized decision</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Approved Requests</span>
            <strong class="stat-value text-success">{{ $approvedCount }}</strong>
            <span class="stat-subtext">Changes applied to documents</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Rejected Requests</span>
            <strong class="stat-value text-danger">{{ $rejectedCount }}</strong>
            <span class="stat-subtext">Declined with recorded reason</span>
        </div>
        <div class="stat-card">
            <span class="stat-label">Total Requests</span>
            <strong class="stat-value">{{ $changes->count() }}</strong>
            <span class="stat-subtext">Preserved in immutable history</span>
        </div>
    </div>

    <section class="panel">
        <div class="panel-header d-flex justify-content-between align-items-center">
            <div>
                <h2>Change Requests ({{ $changes->count() }})</h2>
                <span class="panel-note">Your submitted requests and documents awaiting your review.</span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-zebra align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 35%">Action &amp; Target Document</th>
                        <th style="width: 25%">Proposed Changes</th>
                        <th style="width: 15%">Status</th>
                        <th style="width: 25%">Decision &amp; Review</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($changes as $change)
                        @php
                            $payloadData = is_string($change->payload) ? json_decode($change->payload, true) : (array) $change->payload;
                            $badgeClass = match ($change->status) {
                                'approved' => 'clean',
                                'pending' => 'pending',
                                'rejected' => 'failed',
                                default => 'neutral',
                            };
                            $actionLabel = match ($change->action) {
                                'memo.publish' => 'Memo Publication',
                                'document.delete' => 'Document Delete (Trash)',
                                'document.update' => 'Metadata / Classification Update',
                                'document.share' => 'Share Access Grant',
                                default => ucfirst(str_replace(['.', '_'], ' ', $change->action)),
                            };
                        @endphp
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <span class="badge bg-primary px-2 py-1" style="font-size: 11px">{{ $actionLabel }}</span>
                                    <small class="text-muted">Rev. #{{ $change->revision }}</small>
                                </div>
                                <div class="fw-semibold">
                                    <a href="/documents/{{ $change->document_id }}" class="text-decoration-none text-dark d-inline-flex align-items-center gap-1">
                                        <x-figma-icon name="file" :size="14" />
                                        <span>Open document</span>
                                    </a>
                                </div>
                                <small class="text-muted d-block mt-1">
                                    Requested by #{{ $change->requester_id }} ({{ ucfirst($change->requester_role ?? 'staff') }}) · {{ \Carbon\Carbon::parse($change->created_at)->diffForHumans() }}
                                </small>
                            </td>

                            <td>
                                <details class="proposed-details">
                                    <summary class="fw-semibold text-primary" style="cursor: pointer; font-size: 13px">
                                        Proposed changes
                                    </summary>
                                    <div class="mt-2 p-2 bg-light rounded border small">
                                        @if (is_array($payloadData) && count($payloadData) > 0)
                                            <dl class="mb-0">
                                                @foreach ($payloadData as $k => $v)
                                                    <dt class="text-muted text-uppercase" style="font-size: 10px">{{ $k }}</dt>
                                                    <dd class="mb-1 fw-semibold font-monospace" style="word-break: break-all">
                                                        @if (is_array($v) || is_object($v))
                                                            {{ json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}
                                                        @elseif (is_bool($v))
                                                            {{ $v ? 'true' : 'false' }}
                                                        @else
                                                            {{ (string) $v }}
                                                        @endif
                                                    </dd>
                                                @endforeach
                                            </dl>
                                        @else
                                            <span class="text-muted fst-italic">No additional parameters</span>
                                        @endif
                                    </div>
                                </details>
                            </td>

                            <td>
                                <span class="status-badge {{ $badgeClass }}">
                                    ● {{ ucfirst($change->status) }}
                                </span>
                                @if ($change->reason)
                                    <div class="mt-1 small text-muted fst-italic p-1 rounded bg-light border" style="max-width: 200px" title="{{ $change->reason }}">
                                        "{{ Str::limit($change->reason, 80) }}"
                                    </div>
                                @endif
                            </td>

                            <td>
                                @if ($change->status === 'pending' && (int) $change->requester_id !== auth()->id())
                                    <form method="post" action="/approvals/{{ $change->id }}" class="d-flex flex-column gap-2">
                                        @csrf
                                        <label for="reason-{{ $change->id }}" class="visually-hidden">Decision Reason</label>
                                        <textarea
                                            id="reason-{{ $change->id }}"
                                            class="form-control form-control-sm"
                                            name="reason"
                                            maxlength="2000"
                                            required
                                            rows="2"
                                            placeholder="Provide reason for approval or rejection…"></textarea>
                                        <div class="d-flex gap-2">
                                            <button class="btn btn-sm btn-primary flex-fill" name="decision" value="approve">Approve</button>
                                            <button class="btn btn-sm btn-outline-danger flex-fill" name="decision" value="reject">Reject</button>
                                        </div>
                                    </form>
                                @else
                                    <div class="small text-muted">
                                        @if ($change->status === 'pending')
                                            <span class="badge bg-light text-secondary border">Awaiting review by another authority</span>
                                        @else
                                            <span>Decided {{ \Carbon\Carbon::parse($change->decided_at ?? $change->updated_at)->format('M d, Y H:i') }}</span>
                                            @if ($change->reviewer_id)
                                                <small class="d-block">Reviewer #{{ $change->reviewer_id }}</small>
                                            @endif
                                        @endif
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-5 text-muted">
                                <x-figma-icon name="check" :size="32" class="opacity-50 mb-2 d-block mx-auto" />
                                <strong>No change requests found.</strong>
                                <p class="mb-0 small">When sensitive operations (like memo publishing or deletions) are requested, they appear here for authorization.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
@endsection
