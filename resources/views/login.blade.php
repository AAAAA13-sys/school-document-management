@extends('layout')
@section('title', 'Sign in')
@section('content')
<main class="login-page" id="main-content" tabindex="-1"><section class="login-brand"><img src="/assets/document-mark.svg" alt="Document management icon"><p class="eyebrow">SCHOOL DOCUMENT MANAGEMENT</p><p class="login-headline">Your documents.<br>All together.</p><p>A simple, secure space for students, employees and school administrators.</p><div class="brand-line"></div><p class="small">Organize · Find · Preserve</p></section>
<section class="login-panel"><div class="login-box"><span class="eyebrow green">DOCUMENT MANAGEMENT</span><h1>Sign in</h1><p class="text-secondary mb-4">Use your school account to access your document workspace.</p>
@if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="/login">@csrf<label for="email" class="form-label">Email address</label><input id="email" name="email" class="form-control mb-3" type="email" value="{{ old('email') }}" autocomplete="username" required><label for="password" class="form-label">Password</label><input id="password" name="password" class="form-control mb-4" type="password" autocomplete="current-password" required><button class="btn btn-green w-100" type="submit">Sign in</button></form>

</div></section></main>
@endsection
