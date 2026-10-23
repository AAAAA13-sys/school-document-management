<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Enums\Source;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user?->active !== true) {
            return false;
        }

        $route = $this->route()->getName();
        if ($route === 'accounts.create') {
            $role = $this->input('role');
            if (is_string($role) && in_array($role, Role::values(), true) && ! $user->can('accounts.role', $role)) {
                return false;
            }

            $source = $this->input('source');
            // Let validation reject malformed source values; only enforce a
            // valid, but out-of-scope, source as a forbidden action here.
            if (($source === null || in_array($source, Source::values(), true)) && ! $user->can('accounts.source', [$source])) {
                return false;
            }

            return true;
        }

        if ($route === 'accounts.update') {
            $query = User::where('school_id', $user->school_id)
                ->where('campus', $user->campus)
                ->whereKey($this->route('account'));
            if ($user->isAdmin() && $user->source) {
                // Another source's account does not exist for this Admin.
                $query->where('source', $user->source);
            }
            $target = $query->first();
            abort_unless($target, 404);

            return $user->can('update', $target);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->route()->getName(), ['accounts.create', 'accounts.update']) && $this->input('role') === Role::SuperAdmin->value) {
            // A Super Admin is intentionally not tied to one ERP source.
            $this->merge(['source' => null]);
        } elseif (is_string($this->input('source'))) {
            $this->merge(['source' => strtoupper(trim($this->input('source')))]);
        }
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    public function rules(): array
    {
        return match ($this->route()->getName()) {
            'folders.create' => ['name' => 'required|string|max:150', 'parent_id' => 'nullable|uuid', 'source' => ['required', Rule::in(Source::values())]],
            'folders.update' => ['name' => 'required|string|max:150', 'parent_id' => 'nullable|uuid', 'revision' => 'required|integer|min:1'],
            'documents.update' => ['classification' => 'sometimes|required|in:Internal,Confidential,Restricted', 'title' => 'required|string|max:200', 'folder_id' => 'nullable|uuid', 'revision' => 'required|integer|min:1', 'confirm_move' => 'sometimes|boolean'],
            'documents.trash', 'documents.restore' => ['revision' => 'required|integer|min:1'],
            'shares.create' => ['recipient_id' => 'required|integer', 'permission' => 'required|in:Viewer,Commenter,Editor', 'expires_at' => 'nullable|date|after:now', 'version_id' => 'nullable|uuid'],
            'folder-shares.create' => ['recipient_id' => 'required|integer', 'permission' => 'required|in:Viewer,Commenter,Editor', 'expires_at' => 'nullable|date|after:now'],
            'comments.create' => ['body' => 'required|string|max:2000'],
            'accounts.create', 'accounts.update' => [
                'name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('account'))],
                'password' => [$this->route('account') ? 'nullable' : 'required', 'string', 'max:255', Password::min(12)->mixedCase()->numbers()->symbols()],
                'role' => ['required', Rule::in(Role::values())],
                'source' => [
                    'nullable',
                    Rule::requiredIf(fn (): bool => $this->input('role') !== Role::SuperAdmin->value),
                    Rule::in(Source::values()),
                ],
                'active' => 'required|boolean',
            ],
            'connectors.create' => ['name' => 'required|string|max:100', 'source' => ['required', Rule::in(Source::values())]],
            default => [],
        };
    }
}
