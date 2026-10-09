@extends('layout')
@section('content')
<main class="login-page"><section class="login-brand"><img src="/assets/document-mark.svg" alt="Document management icon"><p class="eyebrow">SCHOOL DOCUMENT MANAGEMENT</p><h1>Every record.<br>A stronger foundation.</h1><p>A shared workspace for the documents that support our school.</p><div class="brand-line"></div><p class="small">Admission · Enrollment · Employees · Payroll</p></section>
<section class="login-panel"><div class="login-box"><span class="eyebrow green">DOCUMENT MANAGEMENT</span><h2>Welcome back</h2><p class="text-secondary mb-4">Sign in to your records workspace.</p>
@if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="/login">@csrf<label for="email" class="form-label">School email</label><input id="email" name="email" class="form-control mb-3" type="email" value="{{ old('email') }}" autocomplete="username" required><label for="password" class="form-label">Password</label><input id="password" name="password" class="form-control mb-4" type="password" autocomplete="current-password" required><button class="btn btn-green w-100" type="submit">Sign in</button></form>
@if(app()->environment('local'))<div class="demo-note"><strong>Local demonstration</strong><p>Synthetic documents only. Demo account: <code>admin@demo.school</code>. Use the password configured in your local setup.</p></div>@endif
</div></section></main>
@endsection
