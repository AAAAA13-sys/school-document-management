@extends('layout')
@section('title', 'Sign in')
@section('content')
<main class="login-page" id="main-content" tabindex="-1"><section class="login-box" aria-labelledby="login-title">
<a class="login-identity" href="/login"><span aria-hidden="true">▤</span> School Documents</a><h1 id="login-title">Sign in</h1><p class="login-description">Access your school document workspace.</p>
@if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
<form method="post" action="/login">@csrf<label for="email" class="form-label">Email</label><input id="email" name="email" class="form-control" type="email" value="{{ old('email') }}" autocomplete="username" required>
<label for="password" class="form-label">Password</label><div class="password-field"><input id="password" name="password" class="form-control" type="password" autocomplete="current-password" required><button type="button" id="password-toggle" aria-controls="password" aria-pressed="false">Show</button></div>
<button class="btn btn-green w-100 login-submit" type="submit">Sign in</button></form><p class="login-footnote">For authorized school staff. Students do not have DMS access. Contact your administrator.</p></section></main>
@endsection
@section('scripts')<script src="/assets/staff-workspace.js?v={{ filemtime(public_path('assets/staff-workspace.js')) }}"></script>@endsection
