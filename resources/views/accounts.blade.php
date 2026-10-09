@extends('layout')
@section('title', 'Accounts & access')
@php($roles = config('dms.role_labels'))
@section('content')
<main class="container accounts-page py-4" id="main-content" tabindex="-1">
<a href="/" class="btn btn-quiet">← Workspace</a>
<header class="accounts-heading"><p class="eyebrow green mt-4">ADMINISTRATION</p><h1>Accounts &amp; access</h1>
<p>Manage who can use your school document workspace.</p><span class="scope-label">{{ auth()->user()->campus }} · {{ $users->total() }} accounts</span></header>
@if(session('status'))<div class="alert alert-success" role="status">{{ session('status') }}</div>@endif
@if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<details class="panel p-4 account-create" @if($errors->any()) open @endif><summary>Create account</summary><p class="form-instruction mt-3">Choose a role that matches the person’s responsibilities. Their account will use your school and campus.</p>
<form action="/accounts" method="post" class="row g-3">@csrf
<div class="col-md-6"><label class="form-label" for="name">Name</label><input class="form-control" id="name" name="name" required maxlength="200" value="{{ old('name') }}"></div>
<div class="col-md-6"><label class="form-label" for="email">Email</label><input class="form-control" id="email" name="email" type="email" required value="{{ old('email') }}"></div>
<div class="col-md-4"><label class="form-label" for="role">Role</label><select class="form-select" id="role" name="role">@foreach($roles as $role=>$label)<option value="{{ $role }}" @selected(old('role','student')===$role)>{{ $label }}</option>@endforeach</select></div>
<div class="col-md-4"><label class="form-label" for="password">Password</label><input class="form-control" id="password" name="password" type="password" autocomplete="new-password" required minlength="12" aria-describedby="password-guidance"><small id="password-guidance" class="form-instruction">At least 12 characters, including letters and numbers.</small></div>
<div class="col-md-4"><label class="form-label" for="password_confirmation">Confirm password</label><input class="form-control" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></div>
<div><button class="btn btn-green">Create account</button></div></form></details>
<section class="panel p-4 mt-4"><h2>Existing accounts</h2><p class="form-instruction">Update a role or account access, then save that row. Your own access must be changed by another administrator.</p><div class="account-columns row" aria-hidden="true"><span class="col-md-5">Person</span><span class="col-md-3">Role</span><span class="col-md-2">Account access</span><span class="col-md-2">Action</span></div>
@foreach($users as $user)
<form action="/accounts/{{ $user->id }}" method="post" class="row g-2 align-items-center border-bottom py-3">@csrf @method('PATCH')
<div class="col-md-5"><strong>{{ $user->name }}</strong><div>{{ $user->email }}</div></div>
<div class="col-md-3"><select class="form-select" name="role" aria-label="Role for {{ $user->name }}" @disabled($user->id===auth()->id())>@if(!array_key_exists($user->role,$roles))<option value="" disabled selected>Select a role</option>@endif @foreach($roles as $role=>$label)<option value="{{ $role }}" @selected($user->role===$role)>{{ $label }}</option>@endforeach</select></div>
<div class="col-md-2"><select class="form-select" name="active" aria-label="Status for {{ $user->name }}" @disabled($user->id===auth()->id())><option value="1" @selected($user->active)>Active</option><option value="0" @selected(!$user->active)>Inactive</option></select></div>
<div class="col-md-2"><button class="btn btn-green" aria-label="Save access for {{ $user->name }}" @disabled($user->id===auth()->id())>Save</button></div></form>
@endforeach
<div class="mt-3">{{ $users->links() }}</div></section></main>
@endsection
