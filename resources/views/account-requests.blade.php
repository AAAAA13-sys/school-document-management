@extends ('layout')
@php
    $viewer = $viewer ?? auth()->user();
    $section = 'accounts';
    $pendingCount = $changes->where('status', 'pending')->count();
@endphp
@section ('title', 'Administration · Account Change Requests')
@section ('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">DMS / STAFF ACCOUNTS</span>
            <h1>Account Change Requests</h1>
            <p>Proposed account modifications remain inactive until approved by Super Admin.</p>
        </div>
    </div>

    <nav class="library-tabs mb-4" aria-label="People management">
        <a href="/admin/accounts">Staff directory</a>
        <a href="/admin/invitations">Invitations</a>
        <a href="/admin/account-requests" class="active" aria-current="page">Account requests</a>
    </nav>

    @if (session('status'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <strong>Success:</strong> {{ session('status') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <strong>Error:</strong> {{ $errors->first() }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <section class="panel">
        <div class="panel-header d-flex justify-content-between align-items-center">
            <div>
                <h2>Requested Account Modifications ({{ $changes->total() }})</h2>
                <span class="panel-note">Live personnel roles and permissions are preserved until approved.</span>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-zebra align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width: 25%">Target Account</th>
                        <th style="width: 35%">Proposed Values</th>
                        <th style="width: 15%">Status</th>
                        <th style="width: 25%">Decision</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($changes as $change)
                        @php
                            $payload = json_decode($change->payload, true) ?: [];
                            $badgeClass = match ($change->status) {
                                'approved' => 'clean',
                                'pending' => 'pending',
                                'rejected' => 'failed',
                                default => 'neutral',
                            };
                        @endphp
                        <tr>
                            <td>
                                <strong class="text-dark">{{ $change->email }}</strong>
                                <small class="text-muted d-block">Account ID: #{{ $change->account_id }}</small>
                                <small class="text-muted d-block">Requested by: #{{ $change->requester_id }} · {{ \Carbon\Carbon::parse($change->created_at)->diffForHumans() }}</small>
                            </td>
                            <td>
                                <div class="p-2 bg-light rounded border small">
                                    <dl class="mb-0">
                                        @foreach ($payload as $key => $value)
                                            <dt class="text-muted text-uppercase" style="font-size: 10px">{{ $key }}</dt>
                                            <dd class="mb-1 fw-semibold font-monospace">
                                                {{ is_bool($value) ? ($value ? 'Yes' : 'No') : (is_array($value) ? json_encode($value) : $value) }}
                                            </dd>
                                        @endforeach
                                    </dl>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge {{ $badgeClass }}">
                                    ● {{ ucfirst($change->status) }}
                                </span>
                                @if ($change->reason)
                                    <small class="d-block text-muted fst-italic mt-1">"{{ $change->reason }}"</small>
                                @endif
                            </td>
                            <td>
                                @if ($change->status === 'pending' && auth()->user()->isSuperAdmin())
                                    <form method="post" action="/admin/account-requests/{{ $change->id }}/decide" class="d-flex flex-column gap-2">
                                        @csrf
                                        <label for="reason-account-{{ $change->id }}" class="visually-hidden">Decision Reason</label>
                                        <input
                                            id="reason-account-{{ $change->id }}"
                                            name="reason"
                                            required
                                            maxlength="2000"
                                            class="form-control form-control-sm"
                                            placeholder="Reason for decision…" />
                                        <div class="d-flex gap-2">
                                            <button name="approve" value="1" class="btn btn-sm btn-primary flex-fill">Approve</button>
                                            <button name="approve" value="0" class="btn btn-sm btn-outline-danger flex-fill">Reject</button>
                                        </div>
                                    </form>
                                @else
                                    <span class="text-muted small">
                                        {{ $change->status === 'pending' ? 'Awaiting Super Admin review' : 'Decision concluded' }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center py-4 text-muted">
                                No account change requests found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($changes->hasPages())
            <div class="p-3 border-top">
                {{ $changes->links() }}
            </div>
        @endif
    </section>
@endsection
