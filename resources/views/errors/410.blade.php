<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>Invitation expired · PLP Documents</title>
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
            <span class="eyebrow">Access link notice</span>
            <h2>Invitation expired or invalid</h2>
            <p class="section-description mb-4">
                {{ !empty($exception) && $exception->getMessage() ? $exception->getMessage() : 'This invitation link is no longer valid. It may have expired, been revoked, or already used.' }}
            </p>
            <div class="alert alert-warning mb-4" role="status">
                <strong>Need access?</strong> If you have not yet set up your account or your link has expired, please contact your DMS administrator or
                department head to request a fresh invitation.
            </div>
            <a href="/login" class="btn btn-primary w-100 mb-3">Return to sign in</a>
            <p class="login-help text-center">
                <a href="/forgot-password">Already have an active account? Reset password</a>
            </p>
            <p class="login-policy">For authorized PLP personnel only. Activity is logged.</p>
        </div>
    </main>
</body>
</html>
