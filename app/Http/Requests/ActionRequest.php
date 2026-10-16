<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (in_array($this->route()->getName(), ['accounts.create', 'accounts.update'])) {
            return in_array($this->user()?->role, ['super_admin','admin']) && ($this->user()->role !== 'admin' || $this->input('role') === 'staff');
        }
        if ($this->route()->getName() === 'connectors.create') return $this->user()?->role === 'super_admin';
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->route()->getName(), ['accounts.create', 'accounts.update']) && $this->input('role') === 'super_admin') {
            $this->merge(['source' => 'DMS']);
        }
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'folders.create' => ['name' => 'required|string|max:150', 'parent_id' => 'nullable|uuid', 'source' => 'required|in:EMS,PMS,ES,OAS,DMS'],
            'folders.update' => ['name' => 'required|string|max:150', 'parent_id' => 'nullable|uuid', 'revision' => 'required|integer|min:1'],
            'documents.update' => ['classification' => 'sometimes|required|in:Internal,Confidential,Restricted', 'title' => 'required|string|max:200', 'folder_id' => 'nullable|uuid', 'revision' => 'required|integer|min:1'],
            'documents.trash', 'documents.restore' => ['revision' => 'required|integer|min:1'],
            'shares.create' => ['recipient_id' => 'required|integer', 'permission' => 'required|in:Viewer,Commenter,Editor', 'expires_at' => 'nullable|date|after:now', 'version_id' => 'nullable|uuid'],
            'comments.create' => ['body' => 'required|string|max:2000'],
            'accounts.create', 'accounts.update' => [
                'name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('account'))],
                'password' => [$this->route('account') ? 'nullable' : 'required', 'string', 'max:255', Password::min(12)->mixedCase()->numbers()->symbols()],
                'role' => 'required|in:super_admin,admin,staff', 'source' => $this->input('role') === 'super_admin' ? 'required|in:DMS' : 'required|in:EMS,PMS,ES,OAS', 'active' => 'required|boolean',
            ],
            'connectors.create' => ['name' => 'required|string|max:100', 'source' => 'required|in:EMS,PMS,ES,OAS'],
            default => [],
        };
    }
}
