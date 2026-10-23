@extends ('layout')
@section ('title','My account')
@section ('content')
    @php
    $person=auth()->user();
    $admin=in_array($person->role,['super_admin','admin']);
    $super=$person->role==='super_admin';
    $accountRole=config('dms.role_labels.'.$person->role);
@endphp
    <div class="page-heading">
        <div>
            <span class="eyebrow">ACCOUNT & SECURITY</span>
            <h1>My account</h1>
            <p>Your profile, workspace access and sign-in security.</p>
        </div>
    </div>
    <div class="account-grid">
        <section class="panel account-profile" aria-labelledby="profile-title">
            <div class="account-identity">
                <span class="account-avatar" aria-hidden="true">{{ mb_strtoupper(mb_substr($person->name,0,1)) }}</span>
                <div>
                    <span class="eyebrow">{{ $admin?'DMS ADMINISTRATION':'STAFF PROFILE' }}</span>
                    <h2 id="profile-title">{{ $person->name }}</h2>
                    <p class="break-text">{{ $person->email }}</p>
                </div>
            </div>
            <dl class="account-facts">
                <div>
                    <dt>Role</dt>
                    <dd>{{ $accountRole }}</dd>
                </div>
                <div>
                    <dt>Workspace access</dt>
                    <dd>{{ $super?'All registered workspaces within your school and campus':implode(' · ', $person->sources()) }}</dd>
                </div>
                <div>
                    <dt>Account status</dt>
                    <dd>
                        <span class="status-badge {{ $person->active?'clean':'neutral' }}">{{ $person->active?'Active':'Inactive' }}</span>
                    </dd>
                </div>
            </dl>
            <div class="account-help">
                <h3>{{ $admin?'Manage school accounts':'Need to update your profile?' }}</h3>
                <p>{{ $admin?'Manage staff profiles, roles and workspace access from Staff accounts.':'Contact your DMS administrator to update your name, email or workspace access.' }}</p>
                @if ($admin)
                    <a class="btn btn-outline-secondary" href="/admin/accounts">Open Staff accounts →</a>
                @endif
            </div>
        </section>
        <section class="panel account-security" aria-labelledby="password-title">
            <div class="account-section-heading">
                <span class="eyebrow">SIGN-IN SECURITY</span>
                <h2 id="password-title">Change password</h2>
                <p>Choose a unique password you do not use elsewhere.</p>
            </div>
            <form method="post" action="/account/password">
                @csrf
                @method ('PUT')
                <div class="mb-4">
                    <label class="form-label" for="current-password">Current password</label
                    ><input
                        id="current-password"
                        name="current_password"
                        type="password"
                        class="form-control @error('current_password') is-invalid @enderror"
                        required
                        autocomplete="current-password"
                        @if ($errors->has('current_password')) aria-invalid="true" aria-describedby="current-password-error" @endif />
                    @error ('current_password')
                        <div id="current-password-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-4">
                    <label class="form-label" for="new-password">New password</label
                    ><input
                        id="new-password"
                        name="password"
                        type="password"
                        class="form-control @error('password') is-invalid @enderror"
                        required
                        minlength="12"
                        maxlength="255"
                        autocomplete="new-password"
                        data-password-policy
                        aria-describedby="password-help @error('password') password-error @enderror" /><small id="password-help" class="account-password-help"
                        >At least 12 characters, with uppercase and lowercase letters, a number and a symbol.</small
                    >
                    @error ('password')
                        <div id="password-error" class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="mb-4">
                    <label class="form-label" for="confirm-password">Confirm new password</label
                    ><input id="confirm-password" name="password_confirmation" type="password" class="form-control" required autocomplete="new-password" />
                </div>
                <div class="account-security-footer">
                    <p>Changing your password signs out your other sessions.</p>
                    <button class="btn btn-primary" type="submit">Update password</button>
                </div>
            </form>
        </section>
    </div>
@endsection
