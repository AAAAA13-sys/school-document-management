@extends ('invitations.layout')
@section ('title', 'Invitation information')
@section ('content')
    @php
        [$heading, $message] = match ($state) {
            'expired' => ['This invitation has expired', 'The registration link is no longer valid. Contact your DMS administrator for a new invitation.'],
            'used' => ['This invitation has already been used', 'This link cannot be used again. If you already created your account, sign in using your invited email and password.'],
            'revoked' => ['This invitation is no longer available', 'This registration link is no longer valid. Contact your DMS administrator if you need access.'],
            'session_expired' => ['Your session has expired', 'Open the invitation from your email again and resubmit the form. Your account was not created by this request.'],
            'limited' => ['Please wait before trying again', 'Too many registration attempts were received. Wait a minute, then open your invitation again.'],
            'signed_in' => ['You are already signed in', 'Use a signed-out browser session to register with this invitation. Your current session has been kept.'],
            'unavailable' => ['Registration is temporarily unavailable', 'We could not complete this request. Try again later or contact your DMS administrator.'],
            default => ['This invitation is not available', 'We could not validate this registration link. Open the latest invitation from your email or contact your DMS administrator.'],
        };
    @endphp
    <span class="eyebrow">STAFF INVITATION</span>
    <h1 id="invitation-title">{{ $heading }}</h1>
    <p class="section-description">{{ $message }}</p>
    <a class="btn btn-primary" href="{{ route('login', [], false) }}">Go to Login</a>
    <p class="login-help">For assistance, contact your DMS administrator or IT support.</p>
@endsection
