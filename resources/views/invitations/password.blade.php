<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>{{ $mode === 'invite' ? 'Activate your account' : 'Reset your password' }} · PLP Documents</title>
    <link rel="stylesheet" href="/vendor/bootstrap.min.css" />
    <link rel="stylesheet" href="/css/dms.css" />
    <script defer src="/vendor/jquery.min.js"></script>
    <script defer src="/vendor/jquery-ui.min.js"></script>
    <script defer src="/vendor/bootstrap.bundle.min.js"></script>
    <script defer src="/js/dms.js"></script>
</head>
<body class="login-page">
    <div class="login-story">
        <a class="brand" href="/login">
            <span class="brand-mark">d.</span><span>PLP Documents<small>DOCUMENT MANAGEMENT SYSTEM</small></span>
        </a>
        <div class="login-message">
            <span class="eyebrow">Pamantasan ng Lungsod ng Pasig</span>
            <h1>A home for every<br />official university record.</h1>
            <p>Store department files securely, track every version, and keep a permanent record of university documents.</p>
            <div class="login-features">
                <div class="login-feature-item">
                    <span class="login-feature-dot"></span>
                    <span><strong>Private and secure</strong> · Every upload is scanned before it's available</span>
                </div>
                <div class="login-feature-item">
                    <span class="login-feature-dot"></span>
                    <span><strong>Permanent version history</strong> · Each file is verified with a checksum</span>
                </div>
                <div class="login-feature-item">
                    <span class="login-feature-dot"></span>
                    <span><strong>Role-based access</strong> · Every view and change is logged</span>
                </div>
            </div>
        </div>
        <span class="login-school">PAMANTASAN NG LUNGSOD NG PASIG · CENTRAL DOCUMENT REPOSITORY</span>
    </div>
    <main class="login-form">
        <div class="login-form-inner">
            <span class="eyebrow">{{ $mode === 'invite' ? 'Account activation' : 'Security credentials' }}</span>
            <h2>{{ $mode === 'invite' ? 'Activate your account' : 'Reset your password' }}</h2>
            <p class="section-description mb-4">
                {{ $mode === 'invite' ? 'Create a secure password to activate your university DMS account.' : 'Enter and confirm your new password below.' }}
            </p>
            @if (session('status'))
                <div class="alert alert-info" role="status">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger" role="alert">
                    @if ($errors->count() > 1)
                        <ul class="mb-0 ps-3">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @else
                        {{ $errors->first() }}
                    @endif
                </div>
            @endif
            <form method="post" action="{{ $mode === 'invite' ? '/invitations/accept/' . $token : '/reset-password' }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}" />
                <div class="mb-4">
                    <label for="email" class="form-label">Email address</label>
                    <input
                        class="form-control"
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email', $email) }}"
                        required
                        {{ $mode === 'invite' ? 'readonly' : 'autocomplete=email' }} />
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="password-field">
                        <input
                            class="form-control @error('password') is-invalid @enderror"
                            id="password"
                            type="password"
                            name="password"
                            autocomplete="new-password"
                            minlength="8"
                            data-password-policy
                            required />
                        <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" title="Show password">
                            <svg class="eye-icon eye-show" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <svg class="eye-icon eye-hide" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="
                                    display: none;
                                ">
                                <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                <line x1="2" x2="22" y1="2" y2="22" />
                            </svg>
                        </button>
                    </div>
                    <div class="form-text mt-2">Must contain at least 8 characters.</div>
                </div>
                <div class="mb-4">
                    <label for="password_confirmation" class="form-label">Confirm password</label>
                    <div class="password-field">
                        <input
                            class="form-control @error('password') is-invalid @enderror"
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            autocomplete="new-password"
                            minlength="8"
                            required />
                        <button class="password-toggle" type="button" aria-label="Show password" aria-pressed="false" title="Show password">
                            <svg class="eye-icon eye-show" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <svg class="eye-icon eye-hide" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="
                                    display: none;
                                ">
                                <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                <line x1="2" x2="22" y1="2" y2="22" />
                            </svg>
                        </button>
                    </div>
                </div>
                <button class="btn btn-primary w-100" type="submit">{{ $mode === 'invite' ? 'Activate account' : 'Save new password' }}</button>
            </form>
            <p class="login-help text-center mt-4">
                <a href="/login">← Back to sign in</a>
            </p>
            <p class="login-policy">For authorized PLP personnel only. Activity is logged.</p>
        </div>
    </main>
</body>
</html>
