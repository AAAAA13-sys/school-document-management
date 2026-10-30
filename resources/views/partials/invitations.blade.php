<section class="panel invitation-panel mt-4" aria-labelledby="invitations-title">
    <div class="panel-header invitation-heading">
        <div>
            <h2 id="invitations-title">Staff invitations</h2>
            <p class="text-muted mb-0">Invite employees to choose their own password. Invitation state and email delivery are tracked separately.</p>
        </div>
        <a href="{{ route('invitations.preview') }}" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">Preview email</a>
    </div>
    @if ($invitationUnavailableReason)
        <div class="alert alert-warning mx-3" role="status">{{ $invitationUnavailableReason }} You can preview the email and manage existing invitations.</div>
    @endif
    <form method="post" action="{{ route('invitations.store') }}" class="invitation-form" data-invitation-submit>
        @csrf
        <div>
            <label for="invitation-email" class="form-label">Employee email</label>
            <input
                id="invitation-email"
                name="email"
                type="email"
                class="form-control"
                maxlength="255"
                value="{{ old('email') }}"
                autocomplete="email"
                required
                @disabled ($invitationUnavailableReason) />
        </div>
        <div>
            <label for="invitation-source" class="form-label">Assigned source</label>
            <select id="invitation-source" name="source" class="form-select" required @disabled ($invitationUnavailableReason)>
                @foreach (array_intersect_key(config('dms.sources'), array_flip($viewer->sources())) as $code => $label)
                    <option value="{{ $code }}" @selected (old('source', $viewer->primarySource()) === $code)>{{ $code }} — {{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="btn btn-primary" @disabled ($invitationUnavailableReason)>Send invitation</button>
        <p class="input-help mb-0">Staff access only. Your school and campus are assigned automatically.</p>
        <span class="visually-hidden" role="status" aria-live="polite" data-invitation-progress></span>
    </form>
    <div class="table-responsive">
        <table class="table table-zebra invitation-table">
            <caption class="visually-hidden">
                Invitation history and email delivery status
            </caption>
            <thead>
                <tr>
                    <th>Employee email</th>
                    <th>Source</th>
                    <th>Invited by</th>
                    <th>Invited / expires (UTC)</th>
                    <th>Invitation</th>
                    <th>Email delivery</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($invitations as $invitation)
                    <tr>
                        <td class="break-text">{{ $invitation->email }}</td>
                        <td>{{ $invitation->source }}</td>
                        <td>{{ $invitation->inviter?->name ?? 'Administrator' }}</td>
                        <td>
                            <time datetime="{{ $invitation->created_at->toIso8601String() }}">{{ $invitation->created_at->format('M d, Y H:i') }}</time
                            ><small class="d-block text-muted">Expires {{ $invitation->expires_at->format('M d, Y H:i') }}</small>
                        </td>
                        <td>
                            <span
                                class="status-badge {{ $invitation->statusLabel() === 'Accepted' ? 'clean' : 'neutral' }}"
                                >{{ $invitation->statusLabel() }}</span
                            >
                        </td>
                        <td>
                            <span
                                class="status-badge {{ $invitation->delivery_status === 'failed' ? 'failed' : ($invitation->delivery_status === 'sent' ? 'clean' : 'pending') }}"
                                >{{ $invitation->deliveryLabel() }}</span
                            >
                            @if ($invitation->delivery_attempted_at)
                                <small class="d-block text-muted">{{ $invitation->delivery_attempted_at->format('M d, H:i') }} UTC</small>
                            @endif
                        </td>
                        <td>
                            @can ('manage', $invitation)
                                @if ($invitation->accepted_at === null && $invitation->revoked_at === null)
                                    <div class="invitation-actions">
                                        <form
                                            method="post"
                                            action="{{ route('invitations.resend', $invitation->id) }}"
                                            data-invitation-submit
                                            data-confirm="Send a new invitation? The previous link will stop working, even if email delivery fails.">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary" type="submit" @disabled ($invitationUnavailableReason)>
                                                Resend
                                            </button>
                                            <span class="visually-hidden" role="status" aria-live="polite" data-invitation-progress></span>
                                        </form>
                                        <form
                                            method="post"
                                            action="{{ route('invitations.revoke', $invitation->id) }}"
                                            data-invitation-submit
                                            data-confirm="Revoke this invitation? Its registration link will stop working.">
                                            @csrf
                                            <input type="hidden" name="confirm_revoke" value="1" />
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Revoke</button>
                                            <span class="visually-hidden" role="status" aria-live="polite" data-invitation-progress></span>
                                        </form>
                                    </div>
                                @else
                                    <span class="text-muted">No actions</span>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-muted text-center py-4">No staff invitations yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($invitations->hasPages())
        <div class="panel-pagination">{{ $invitations->links('pagination::bootstrap-5') }}</div>
    @endif
</section>
