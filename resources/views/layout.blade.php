<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Workspace') · School Documents</title><link rel="icon" href="/assets/favicon.svg">
<link rel="stylesheet" href="/vendor/bootstrap.min.css?v={{ filemtime(public_path('vendor/bootstrap.min.css')) }}"><link rel="stylesheet" href="/vendor/jquery-ui.css?v={{ filemtime(public_path('vendor/jquery-ui.css')) }}"><link rel="stylesheet" href="/assets/app.css?v={{ filemtime(public_path('assets/app.css')) }}">
<link rel="stylesheet" href="/assets/drive-layout.css?v={{ filemtime(public_path('assets/drive-layout.css')) }}">
<link rel="stylesheet" href="/assets/workspace-refresh.css?v={{ filemtime(public_path('assets/workspace-refresh.css')) }}">
<link rel="stylesheet" href="/assets/staff-workspace.css?v={{ filemtime(public_path('assets/staff-workspace.css')) }}">
</head><body><a class="skip-link" href="#main-content">Skip to main content</a>@yield('content')
@auth
<div id="signout-dialog" title="Sign out" hidden><p class="signout-lead">Sign out of School Documents?</p><p class="signout-note">You will need your email and password to sign in again. Your files, shared documents and received history stay exactly where they are.</p><div class="dialog-actions"><button type="button" id="signout-cancel" class="btn btn-quiet">Stay signed in</button><button type="submit" id="signout-confirm" class="btn btn-yellow" form="signout-form">Sign out</button></div></div>
@endauth
<script src="/vendor/jquery.min.js?v={{ filemtime(public_path('vendor/jquery.min.js')) }}"></script><script src="/vendor/jquery-ui.min.js?v={{ filemtime(public_path('vendor/jquery-ui.min.js')) }}"></script>
@auth
<script src="/assets/dialog-scroll.js?v={{ filemtime(public_path('assets/dialog-scroll.js')) }}"></script><script src="/assets/signout.js?v={{ filemtime(public_path('assets/signout.js')) }}"></script>
@endauth
@yield('scripts')
</body></html>
