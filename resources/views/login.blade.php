<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>Sign in · PLP Documents</title>
    <link rel="stylesheet" href="/vendor/bootstrap.min.css" />
    <link rel="stylesheet" href="/css/dms.css?v={{ @filemtime(public_path('css/dms.css')) ?: '1' }}" />
    <script defer src="/vendor/jquery.min.js"></script>
    <script defer src="/vendor/bootstrap.bundle.min.js"></script>
    <script defer src="/js/dms.js?v={{ @filemtime(public_path('js/dms.js')) ?: '1' }}"></script>
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
                    <label for="email" class="form-label">Email address</label>
                    <input
                        class="form-control @error('email') is-invalid @enderror"
                        id="email"
                        type="email"
                        name="email"
                        value="{{ old('email') }}"
                        autocomplete="email"
                        required
                        @error('email') aria-invalid="true" aria-describedby="email-error" @enderror
                        placeholder="you@plp.edu.ph" />
                    @error('email')
                        <p id="email-error" class="form-text text-danger" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="password-field">
                        <input
                            class="form-control @error('password') is-invalid @enderror"
                            id="password"
                            type="password"
                            name="password"
                            autocomplete="current-password"
                            required
                            @error('password') aria-invalid="true" aria-describedby="password-error" @enderror />
                        <button
                            class="password-toggle"
                            type="button"
                            id="password-toggle"
                            aria-label="Show password"
                            aria-pressed="false"
                            title="Show password">
                            <svg class="eye-icon eye-show" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <svg class="eye-icon eye-hide" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="display: none;">
                                <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24" />
                                <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68" />
                                <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61" />
                                <line x1="2" x2="22" y1="2" y2="22" />
                            </svg>
                        </button>
                    </div>
                    @error('password')
                        <p id="password-error" class="form-text text-danger" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <button class="btn btn-primary w-100" type="submit">Sign in</button>
            </form>
            <p class="login-help"><a href="/forgot-password">Forgot your password?</a><br />Need an account? Request an invitation from your DMS administrator.</p>
            <p class="login-policy">For authorized PLP personnel only. Activity is logged.</p>
        </div>
    </main>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.password-toggle').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var field = btn.closest('.password-field');
                    if (!field) return;
                    var input = field.querySelector('input');
                    if (!input) return;
                    var isPassword = input.type === 'password';
                    input.type = isPassword ? 'text' : 'password';
                    var eyeShow = btn.querySelector('.eye-show');
                    var eyeHide = btn.querySelector('.eye-hide');
                    if (eyeShow && eyeHide) {
                        eyeShow.style.display = isPassword ? 'none' : 'block';
                        eyeHide.style.display = isPassword ? 'block' : 'none';
                    } else {
                        btn.textContent = isPassword ? 'Hide' : 'Show';
                    }
                    btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                    btn.setAttribute('aria-pressed', isPassword ? 'true' : 'false');
                    btn.setAttribute('title', isPassword ? 'Hide password' : 'Show password');
                    input.focus();
                });
            });
        });
    </script>
</body>
</html>
