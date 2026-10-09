<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Workspace') · School Documents</title><link rel="icon" href="/assets/favicon.svg">
<link rel="stylesheet" href="/vendor/bootstrap.min.css"><link rel="stylesheet" href="/vendor/jquery-ui.css"><link rel="stylesheet" href="/assets/app.css">
<link rel="stylesheet" href="/assets/drive-layout.css">
<link rel="stylesheet" href="/assets/workspace-refresh.css?v={{ filemtime(public_path('assets/workspace-refresh.css')) }}">
</head><body><a class="skip-link" href="#main-content">Skip to main content</a>@yield('content')
<script src="/vendor/jquery.min.js"></script><script src="/vendor/jquery-ui.min.js"></script>@yield('scripts')
</body></html>
