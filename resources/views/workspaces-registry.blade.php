@extends('layout')
@section('title', 'Workspace registry')
@section('content')
<h1>Workspace registry</h1>
<p>Stable codes identify workspaces. Names can change; archived workspaces stop accepting ordinary workspace access.</p>
<form method="post" action="{{ route('workspaces.registry.store') }}" class="card card-body mb-4">
@csrf
<h2 class="h5">Add workspace</h2>
<label for="workspace-code">Stable code</label><input id="workspace-code" name="code" required maxlength="64" pattern="[A-Za-z][A-Za-z0-9_-]{0,63}" class="form-control mb-2">
<label for="workspace-name">Name</label><input id="workspace-name" name="name" required maxlength="150" class="form-control mb-2">
<label for="workspace-kind">Kind</label><select id="workspace-kind" name="kind" class="form-select mb-2"><option value="erp">ERP</option><option value="internal">Internal</option></select>
<button class="btn btn-primary">Create workspace</button>
</form>
@foreach($workspaces as $workspace)
<section class="card card-body mb-3">
<h2 class="h5">{{ $workspace->code }} <small>({{ $workspace->kind }})</small></h2>
<form method="post" action="{{ route('workspaces.registry.update', $workspace->id) }}">
@csrf @method('PATCH')
<label for="name-{{ $workspace->id }}">Name</label><input id="name-{{ $workspace->id }}" name="name" value="{{ $workspace->name }}" required maxlength="150" class="form-control mb-2">
<label for="status-{{ $workspace->id }}">Lifecycle</label><select id="status-{{ $workspace->id }}" name="status" class="form-select mb-2">@foreach(['active','archived'] as $state)<option value="{{ $state }}" @selected($workspace->status === $state)>{{ ucfirst($state) }}</option>@endforeach</select>
<button class="btn btn-secondary">Save workspace</button>
</form>
<p class="mt-3">{{ ($memberships[$workspace->id] ?? collect())->count() }} assigned members</p>
<form method="post" action="{{ route('workspaces.registry.membership', $workspace->id) }}">
@csrf
<label for="member-{{ $workspace->id }}">Account</label><select id="member-{{ $workspace->id }}" name="user_id" class="form-select mb-2">@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->name }} ({{ $user->email }})</option>@endforeach</select>
<button name="action" value="grant" class="btn btn-primary">Grant membership</button> <button name="action" value="revoke" class="btn btn-outline-danger">Revoke membership</button>
</form>
</section>
@endforeach
@endsection
