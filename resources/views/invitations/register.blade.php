@extends ('invitations.layout')
@section ('title', 'Complete registration')
@section ('content')
    <span class="eyebrow">STAFF INVITATION</span>
    <h1 id="invitation-title">Welcome to PLP Documents</h1>
    <p class="section-description">Complete your account to access your assigned document workspace.</p>
    @if ($signedIn)
        <div class="alert alert-warning" role="status">You are already signed in. Use a signed-out browser session to complete this invitation.</div>
    @endif
    @if ($validationErrors)
        <div class="alert alert-danger" role="alert" tabindex="-1" id="registration-errors">
            <strong>Please check the following:</strong>
            <ul class="mb-0">
                @foreach ($validationErrors as $messages) @foreach ($messages as $message)
                    <li>{{ $message }}</li>
                @endforeach @endforeach
            </ul>
        </div>
    @endif
    <form method="post" action="{{ $formAction }}" id="invitation-registration-form">
        @csrf
        <fieldset @disabled ($signedIn)>
            <legend class="invitation-form-legend">Employee information</legend>
            <div class="mb-3">
                <label class="form-label" for="invited-email">Invited email address</label>
                <input
                    class="form-control"
                    id="invited-email"
                    type="email"
                    name="email"
                    value="{{ $invitedEmail }}"
                    readonly
                    aria-describedby="invited-email-help"
                    autocomplete="username" />
                <small class="input-help" id="invited-email-help">This account will use the email address assigned to your invitation.</small>
            </div>
            <div class="mb-3">
                <label class="form-label" for="full-name">Full name</label>
                <input
                    class="form-control"
                    id="full-name"
                    name="name"
                    value="{{ $fullName }}"
                    autocomplete="name"
                    maxlength="255"
                    required
                    @if (isset($validationErrors['name'])) aria-invalid="true" @endif />
            </div>
            <p class="invitation-source"><strong>Assigned workspace</strong><br />{{ $assignedSource }} · {{ config('dms.sources.'.$assignedSource) }}</p>
        </fieldset>
        <fieldset @disabled ($signedIn)>
            <legend class="invitation-form-legend">Account credentials</legend>
            <div class="mb-3">
                <label class="form-label" for="registration-password">New password</label>
                <div class="invitation-password-control">
                    <input
                        class="form-control"
                        id="registration-password"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        minlength="12"
                        maxlength="255"
                        required
                        aria-describedby="registration-password-help"
                        @if (isset($validationErrors['password'])) aria-invalid="true" @endif />
                    <button
                        class="btn btn-outline-secondary"
                        type="button"
                        data-toggle-password
                        aria-controls="registration-password"
                        aria-pressed="false"
                        aria-label="Show new password">
                        Show
                    </button>
                </div>
                <small class="input-help" id="registration-password-help"
                    >At least 12 characters, with uppercase and lowercase letters, a number, and a symbol. With bcrypt, passwords must fit within 72 bytes;
                    non-ASCII characters may use more than one byte.</small
                >
            </div>
            <div class="mb-4">
                <label class="form-label" for="registration-password-confirmation">Confirm password</label>
                <div class="invitation-password-control">
                    <input
                        class="form-control"
                        id="registration-password-confirmation"
                        name="password_confirmation"
                        type="password"
                        autocomplete="new-password"
                        minlength="12"
                        maxlength="255"
                        required
                        @if (isset($validationErrors['password'])) aria-invalid="true" @endif />
                    <button
                        class="btn btn-outline-secondary"
                        type="button"
                        data-toggle-password
                        aria-controls="registration-password-confirmation"
                        aria-pressed="false"
                        aria-label="Show password confirmation">
                        Show
                    </button>
                </div>
            </div>
            <button class="btn btn-primary w-100" type="submit" id="complete-registration">Complete Registration</button>
            <p class="visually-hidden" role="status" aria-live="polite" id="registration-progress"></p>
        </fieldset>
    </form>
    <p class="login-help">This invitation can be used once. If you need help, contact your DMS administrator or IT support.</p>
    <a class="text-link" href="{{ route('login', [], false) }}">Go to Login</a>
@endsection
