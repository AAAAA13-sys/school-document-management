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
                <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
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
                    <div class="input-group">
                        <input
                            class="form-control"
                            id="password"
                            type="password"
                            name="password"
                            autocomplete="new-password"
                            minlength="12"
                            data-password-policy
                            required />
                        <button class="btn btn-outline-secondary password-toggle" type="button" aria-label="Show password">Show</button>
                    </div>
                    <div class="form-text mt-2">Must contain at least 12 characters, including uppercase, lowercase, numbers, and symbols.</div>
                </div>
                <div class="mb-4">
                    <label for="password_confirmation" class="form-label">Confirm password</label>
                    <div class="input-group">
                        <input
                            class="form-control"
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            autocomplete="new-password"
                            minlength="12"
                            required />
                        <button class="btn btn-outline-secondary password-toggle" type="button" aria-label="Show password">Show</button>
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
