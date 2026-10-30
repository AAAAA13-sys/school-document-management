@extends ('layout')
@php
    $viewer = $viewer ?? auth()->user();
    $section = 'accounts';
@endphp
@section ('title', 'Administration · Account invitations')
@section ('content')
    <div class="page-heading">
        <div>
            <span class="eyebrow">DMS / ACCOUNT INVITATIONS</span>
            <h1>Account invitations</h1>
            <p>Manage single-use invitation links and activation states across authorized personnel.</p>
        </div>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#invitation-new">
            {{ $viewer->isSuperAdmin() ? 'Invite staff member' : 'Propose invitation' }}
        </button>
    </div>

    <nav class="library-tabs" aria-label="People management">
        <a href="/admin/accounts">Staff directory</a>
        <a href="/admin/invitations" class="active" aria-current="page">Invitations</a>
        <a href="/admin/account-requests">Account requests</a>
    </nav>

    <section class="panel">
        <div class="panel-header">
            <h2>Invitations ({{ $invitations->total() }})</h2>
            <span class="panel-note">Single-use links expire within 48 hours of transmission.</span>
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-zebra">
                <thead>
                    <tr>
                        <th>Recipient</th>
                        <th>Role &amp; Workspace</th>
                        <th>Status</th>
                        <th>Expires</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invitations as $invitation)
                        @php
                            $badgeClass = match ($invitation->status) {
                                'approved', 'sent', 'accepted' => 'clean',
                                'pending' => 'pending',
                                'revoked', 'rejected' => 'failed',
                                default => 'neutral',
                            };
                            $roleLabel = config('dms.role_labels.' . $invitation->role) ?? ucfirst(str_replace('_', ' ', $invitation->role));
                        @endphp
                        <tr>
                            <td>
                                <strong>{{ $invitation->name }}</strong>
                                <small class="text-muted d-block">{{ $invitation->email }}</small>
                            </td>
                            <td>
                                <span>{{ $roleLabel }}</span>
                                <small class="text-muted d-block">{{ $invitation->source ? $invitation->source . ' workspace' : 'Super Admin (All)' }}</small>
                            </td>
                            <td>
                                <span class="status-badge {{ $badgeClass }}">{{ ucfirst($invitation->status) }}</span>
                                @if ($invitation->reason)
                                    <small
                                        class="text-muted d-block text-truncate"
                                        style="max-width: 220px"
                                        title="{{ $invitation->reason }}"
                                        >{{ $invitation->reason }}</small
                                    >
                                @endif
                            </td>
                            <td>
                                @if ($invitation->expires_at)
                                    <span>{{ \Illuminate\Support\Carbon::parse($invitation->expires_at)->format('M d, Y H:i') }}</span>
                                    <small class="text-muted d-block">{{ \Illuminate\Support\Carbon::parse($invitation->expires_at)->diffForHumans() }}</small>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <div class="d-inline-flex gap-2 justify-content-end align-items-center">
                                    @if ($viewer->isSuperAdmin())
                                        @if ($invitation->status === 'pending')
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modal-review-{{ $invitation->id }}">
                                                Review
                                            </button>
                                        @endif
                                        @if (in_array($invitation->status, ['approved', 'sent']))
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-primary"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modal-send-{{ $invitation->id }}">
                                                {{ $invitation->status === 'sent' ? 'Resend' : 'Send' }}
                                            </button>
                                        @endif
                                    @endif

                                    @if (in_array($invitation->status, ['pending', 'approved', 'sent']))
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modal-revoke-{{ $invitation->id }}">
                                            Revoke
                                        </button>
                                    @elseif (!in_array($invitation->status, ['pending', 'approved', 'sent']) && !($viewer->isSuperAdmin() && $invitation->status === 'pending'))
                                        <span class="text-muted small">—</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr class="is-empty">
                            <td colspan="5" class="py-5 text-center text-muted">No invitations found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($invitations->hasPages())
            <div class="panel-pagination">{{ $invitations->links('pagination::bootstrap-5') }}</div>
        @endif
    </section>
@endsection

@push ('dialogs')
    <div class="modal fade" id="invitation-new" tabindex="-1" aria-labelledby="invitation-new-title" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="/admin/invitations">
                    @csrf
                    <div class="modal-header">
                        <h2 id="invitation-new-title" class="modal-title">
                            {{ $viewer->isSuperAdmin() ? 'Invite staff member' : 'Propose staff invitation' }}
                        </h2>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p class="modal-subtitle">
                            {{ $viewer->isSuperAdmin()
                                ? 'An expiring single-use invitation link will be generated for the recipient to activate their account.'
                                : 'Staff invitations proposed by Admins require Super Admin approval before delivery.' }}
                        </p>
                        <div class="mb-4">
                            <label for="invite-name" class="form-label">Full name</label>
                            <input id="invite-name" name="name" class="form-control" maxlength="255" required placeholder="Enter recipient's full name" />
                        </div>
                        <div class="mb-4">
                            <label for="invite-email" class="form-label">Email address</label>
                            <input id="invite-email" name="email" type="email" class="form-control" maxlength="255" required placeholder="staff@plp.edu.ph" />
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-sm-6">
                                <label for="invite-role" class="form-label">Role</label>
                                <select id="invite-role" name="role" class="form-select" data-account-role>
                                    @if ($viewer->isSuperAdmin())
                                        <option value="staff" selected>Staff</option>
                                        <option value="admin">Admin</option>
                                        <option value="super_admin">Super Admin</option>
                                    @else
                                        <option value="staff" selected>Staff</option>
                                    @endif
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label for="invite-source" class="form-label">Primary workspace</label>
                                <select id="invite-source" name="source" class="form-select" data-account-source>
                                    @if ($viewer->isSuperAdmin())
                                        <option value="">None (Super Admin only)</option>
                                    @endif
                                    @foreach (App\Services\WorkspaceRegistry::labels($viewer) as $code => $label)
                                        <option value="{{ $code }}" @selected ($viewer->source === $code)>{{ $code }} — {{ $label }}</option>
                                    @endforeach
                                </select>
                                <small class="input-help">Super Admin oversees all workspaces.</small>
                            </div>
                        </div>
                        <p class="input-help">Recipients will choose their own password via the activation link.</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">{{ $viewer->isSuperAdmin() ? 'Send invitation' : 'Submit proposal' }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    @foreach ($invitations as $invitation)
        @if ($viewer->isSuperAdmin() && $invitation->status === 'pending')
            <div
                class="modal fade"
                id="modal-review-{{ $invitation->id }}"
                tabindex="-1"
                aria-labelledby="modal-review-title-{{ $invitation->id }}"
                aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post" action="/admin/invitations/{{ $invitation->id }}/review">
                            @csrf
                            <div class="modal-header">
                                <h2 class="modal-title" id="modal-review-title-{{ $invitation->id }}">Review invitation</h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p class="modal-subtitle">Review proposed invitation for <strong>{{ $invitation->name }}</strong> ({{ $invitation->email }}).</p>
                                <div class="mb-3">
                                    <span class="text-muted d-block small">Role &amp; Workspace</span>
                                    <strong
                                        >{{ config('dms.role_labels.' . $invitation->role) ?? ucfirst(str_replace('_', ' ', $invitation->role)) }} · {{ $invitation->source ?: 'All workspaces' }}</strong
                                    >
                                </div>
                                <div class="mb-3">
                                    <label for="review-reason-{{ $invitation->id }}" class="form-label"
                                        >Decision reason <span class="text-danger">*</span></label
                                    >
                                    <textarea
                                        id="review-reason-{{ $invitation->id }}"
                                        name="reason"
                                        class="form-control"
                                        rows="3"
                                        required
                                        maxlength="2000"
                                        placeholder="Provide a justification or explanation for this decision..."></textarea>
                                </div>
                            </div>
                            <div class="modal-footer d-flex justify-content-between">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <div class="d-flex gap-2">
                                    <button type="submit" name="approve" value="0" class="btn btn-outline-danger">Reject</button>
                                    <button type="submit" name="approve" value="1" class="btn btn-success">Approve</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        @if ($viewer->isSuperAdmin() && in_array($invitation->status, ['approved', 'sent']))
            <div
                class="modal fade"
                id="modal-send-{{ $invitation->id }}"
                tabindex="-1"
                aria-labelledby="modal-send-title-{{ $invitation->id }}"
                aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post" action="/admin/invitations/{{ $invitation->id }}/send">
                            @csrf
                            <div class="modal-header">
                                <h2 class="modal-title" id="modal-send-title-{{ $invitation->id }}">
                                    {{ $invitation->status === 'sent' ? 'Resend invitation' : 'Send invitation' }}
                                </h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p class="modal-subtitle">
                                    {{ $invitation->status === 'sent'
                                        ? 'Generate and send a fresh single-use activation email to '
                                        : 'Send a single-use activation email to ' }}
                                    <strong>{{ $invitation->email }}</strong>.
                                </p>
                                @if ($invitation->status === 'sent')
                                    <div class="alert alert-warning mb-0" role="status">
                                        <strong>Notice:</strong> Resending will invalidate any previously sent activation links for this invitation.
                                    </div>
                                @endif
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-primary">{{ $invitation->status === 'sent' ? 'Resend link' : 'Send link' }}</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        @if (in_array($invitation->status, ['pending', 'approved', 'sent']))
            <div
                class="modal fade"
                id="modal-revoke-{{ $invitation->id }}"
                tabindex="-1"
                aria-labelledby="modal-revoke-title-{{ $invitation->id }}"
                aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post" action="/admin/invitations/{{ $invitation->id }}/revoke">
                            @csrf
                            <div class="modal-header">
                                <h2 class="modal-title" id="modal-revoke-title-{{ $invitation->id }}">Revoke invitation</h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p class="modal-subtitle">Are you sure you want to revoke the invitation for <strong>{{ $invitation->name }}</strong> ({{ $invitation->email }})?</p>
                                <p class="text-danger small mb-0">The activation link will be invalidated immediately and cannot be used to activate an account.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-danger">Revoke invitation</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    @endforeach
@endpush
