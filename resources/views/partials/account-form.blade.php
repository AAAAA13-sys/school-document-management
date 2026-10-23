@csrf
@if (isset($account)) @method ('PATCH')@endif
@php $suffix=$account->id??'new'; @endphp
<div class="mb-4">
    <label for="name-{{ $suffix }}" class="form-label">Full name</label
    ><input
        id="name-{{ $suffix }}"
        name="name"
        class="form-control"
        maxlength="255"
        value="{{ $account->name??'' }}"
        required
        placeholder="Enter the staff member’s full name" />
</div>
<div class="mb-4">
    <label for="email-{{ $suffix }}" class="form-label">Email address</label
    ><input
        id="email-{{ $suffix }}"
        name="email"
        type="email"
        class="form-control"
        maxlength="255"
        value="{{ $account->email??'' }}"
        required
        placeholder="staff@plp.edu.ph" />
</div>
<div class="row g-3 mb-4">
    <div class="col-sm-6">
        <label for="role-{{ $suffix }}" class="form-label">Role</label
        ><select id="role-{{ $suffix }}" name="role" class="form-select" data-account-role>
            @foreach (($viewer->role==='super_admin'?config('dms.role_labels'):['staff'=>'Staff']) as $key=>$label)
                <option value="{{ $key }}" @selected (($account->role??'staff')===$key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-sm-6">
        <label for="source-{{ $suffix }}" class="form-label">Primary workspace</label
        ><select id="source-{{ $suffix }}" name="source" class="form-select" data-account-source>
            @foreach (($workspaceLabels ?? App\Services\WorkspaceRegistry::labels($viewer)) as $code=>$label)
                <option value="{{ $code }}" @selected (($account->source??'EMS')===$code)>{{ $code }} - {{ $label }}</option>
            @endforeach
        </select>
        <small class="input-help">Super Admin has no primary source and oversees registered workspaces.</small>
    </div>
</div>
<p class="input-help">Recipients set their own password through a single-use invitation or recovery email.</p>
<div>
    <label for="active-{{ $suffix }}" class="form-label">Account status</label
    ><select id="active-{{ $suffix }}" class="form-select" name="active">
        <option value="1" @selected ($account->active??true)>Active</option>
        <option value="0" @selected (isset($account) && !$account->active)>Inactive</option>
    </select>
</div>
<p class="security-note mt-4 mb-0">Only authorized staff may have DMS accounts.</p>
