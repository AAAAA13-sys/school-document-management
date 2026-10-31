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
        pattern="^[A-Za-zÀ-ÖØ-öø-ÿ\s\.\-']{2,}$"
        title="Full name may only contain letters, spaces, hyphens, periods, and apostrophes."
        value="{{ $account->name??'' }}"
        required
        placeholder="Enter the staff member’s full name" />
    <div class="invalid-feedback realtime-feedback" id="name-{{ $suffix }}-error" style="display: none;"></div>
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
    <div class="invalid-feedback realtime-feedback" id="email-{{ $suffix }}-error" style="display: none;"></div>
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
    </div>
    @if ($viewer->isSuperAdmin())
        <div class="col-12 mt-1 super-admin-source-help">
            <small class="input-help text-nowrap d-block">Super Admin has no primary source and oversees registered workspaces.</small>
        </div>
    @endif
</div>
<input type="hidden" name="active" value="{{ ($account->active ?? true) ? '1' : '0' }}" />
