<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Password recovery · DMS</title>
    <link rel="stylesheet" href="/vendor/bootstrap.min.css" />
</head>
<body>
    <main class="container py-5">
        <h1>Recover your account</h1>
        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif
        @if ($errors->any())
            <p role="alert">{{ $errors->first() }}</p>
        @endif
        <form method="post" action="/forgot-password">
            @csrf
            <label>Email<input class="form-control" name="email" type="email" required autocomplete="email" /></label
            ><button class="btn btn-primary mt-3">Send recovery email</button>
        </form>
        <a href="/login">Back to sign in</a>
    </main>
</body>
</html>
