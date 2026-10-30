<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <meta name="referrer" content="no-referrer" />
    <title>
        @yield ('title')
        · PLP Documents
    </title>
    <link rel="stylesheet" href="{{ request()->getBaseUrl() }}/vendor/bootstrap.min.css" />
    <link rel="stylesheet" href="{{ request()->getBaseUrl() }}/css/dms.css" />
    <script defer src="{{ request()->getBaseUrl() }}/js/invitation-registration.js"></script>
</head>
<body class="invitation-registration-page">
    <main class="invitation-shell">
        <header class="invitation-brand">
            <a class="brand" href="{{ route('login', [], false) }}"
                ><span class="brand-mark">d.</span><span>PLP Documents<small>DOCUMENT MANAGEMENT SYSTEM</small></span></a
            >
        </header>
        <section class="invitation-registration-card" aria-labelledby="invitation-title">
            @yield ('content')
        </section>
        <footer class="invitation-footer">Pamantasan ng Lungsod ng Pasig · For authorized personnel only.</footer>
    </main>
</body>
</html>
