<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>Sign in · PLP Documents</title>
    <link rel="stylesheet" href="/vendor/bootstrap.min.css" />
    <link rel="stylesheet" href="/css/dms.css" />
    <script defer src="/vendor/bootstrap.bundle.min.js"></script>
</head>
<body class="login-page">
    <a class="skip-link" href="#login-form">Skip to sign-in form</a>
    <div class="login-story">
        <a class="brand" href="/login"
            ><span class="brand-mark">d.</span><span>PLP Documents<small>DOCUMENT MANAGEMENT SYSTEM</small></span></a
        >
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
            <span class="eyebrow">Staff access</span>
            <h2>Sign in to your account</h2>
            <p class="section-description mb-4">Sign in with your invited email address and DMS password.</p>
            @if (session('status'))
                <div class="alert alert-info" role="status">{{ session('status') }}</div>
            @endif
            @if (session('registration_complete'))
                <div class="alert alert-success" role="status">
                    <strong>Your account has been created.</strong><br />Sign in with your invited email and the password you chose.
                    <a class="alert-link" href="#email">Go to Login</a>
                </div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
            @endif
            <form method="post" action="/login" id="login-form">
                @csrf
                <div class="mb-4">
                    <label for="email" class="form-label">Email address</label
                    ><input
                        class="form-control @error('email') is-invalid @enderror"
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        autocomplete="email"
                        required
                        @error ('email') aria-invalid="true" aria-describedby="email-error" @enderror
                        placeholder="you@plp.edu.ph" />
                    @error ('email')
                        <p id="email-error" class="form-text text-danger" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label
                    ><input
                        class="form-control @error('password') is-invalid @enderror"
                        id="password"
                        type="password"
                        name="password"
                        autocomplete="current-password"
                        required
                        @error ('password') aria-invalid="true" aria-describedby="password-error" @enderror />
                    @error ('password')
                        <p id="password-error" class="form-text text-danger" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <button class="btn btn-primary w-100" type="submit">Sign in</button>
            </form>
            <p class="login-help"><a href="/forgot-password">Forgot your password?</a><br />Need an account? Request an invitation from your DMS administrator.</p>
            <p class="login-policy">For authorized PLP personnel only. Activity is logged.</p>
        </div>
    </main>
</body>
</html>
