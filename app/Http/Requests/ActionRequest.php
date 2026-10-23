<?php

namespace App\Http\Requests;

use App\Enums\Role;
use App\Enums\Source;
use App\Models\User;
use App\Services\Access;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if ($user?->active !== true) {
            return false;
        }

        $route = $this->route()->getName();
        if ($route === 'folders.create' && is_string($this->input('source'))) {
            $registered = Access::scope(\Illuminate\Support\Facades\DB::table('workspaces'), $user)->where('code', $this->input('source'))->exists();
            return ! $registered || $user->canAccessSource($this->input('source'));
        }
        if ($route === 'accounts.create') {
            $role = $this->input('role');
            if (is_string($role) && in_array($role, Role::values(), true) && ! $user->can('accounts.role', $role)) {
                return false;
            }

            $source = $this->input('source');
            // Let validation reject malformed source values; only enforce a
            // valid, but out-of-scope, source as a forbidden action here.
            if (($source === null || Access::scope(\Illuminate\Support\Facades\DB::table('workspaces'), $user)->where('code', $source)->exists()) && ! $user->can('accounts.source', [$source])) {
                return false;
            }

            return true;
        }

        if ($route === 'accounts.update') {
            $query = User::where('school_id', $user->school_id)
                ->where('campus', $user->campus)
                ->whereKey($this->route('account'));
            if ($user->isAdmin()) {
                // Another source's account does not exist for this Admin.
                $query->whereIn('source', $user->sources());
            }
            $target = $query->first();
            abort_unless($target, 404);

            return $user->can('update', $target);
        }

        if (in_array($route, ['shares.create', 'shares.revoke'], true)) {
            // Resolve visibility before validation so an out-of-scope document
            // stays a 404 and a Super Admin's non-Restricted share attempt is a
            // 403 rather than a validation error.
            $document = Access::document($user, (string) $this->route('document'));

            return $user->can('document.share', $document);
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
            'folders.create' => ['name' => 'required|string|max:150', 'parent_id' => 'nullable|uuid', 'source' => ['required', Rule::in(Source::values($this->user()))]],
            'folders.update' => ['name' => 'required|string|max:150', 'parent_id' => 'nullable|uuid', 'revision' => 'required|integer|min:1'],
            'documents.update' => ['classification' => 'sometimes|required|in:Internal,Confidential,Restricted', 'title' => 'required|string|max:200', 'folder_id' => 'nullable|uuid', 'revision' => 'required|integer|min:1', 'confirm_move' => 'sometimes|boolean'],
            'documents.trash', 'documents.restore' => ['revision' => 'required|integer|min:1'],
            'shares.revoke' => ['revision' => 'required|integer|min:1'],
            'shares.create' => ['revision' => 'required|integer|min:1', 'recipient_id' => 'required|integer', 'permission' => 'required|in:Viewer,Commenter,Editor', 'expires_at' => 'nullable|date|after:now|before_or_equal:'.now()->addDays(30)->toDateTimeString(), 'version_id' => 'nullable|uuid', 'folder_inheritance' => 'sometimes|boolean'],
            'folder-shares.create' => ['recipient_id' => 'required|integer', 'permission' => 'required|in:Viewer,Commenter,Editor', 'expires_at' => 'nullable|date|after:now|before_or_equal:'.now()->addDays(30)->toDateTimeString(), 'apply_to_folder_files' => 'sometimes|boolean'],
            'comments.create' => ['body' => 'required|string|max:2000'],
            'accounts.create', 'accounts.update' => [
                'name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($this->route('account'))],
                'password' => 'prohibited',
                'role' => ['required', Rule::in(Role::values())],
                'source' => [
                    'nullable',
                    Rule::requiredIf(fn (): bool => $this->input('role') !== Role::SuperAdmin->value),
                    Rule::in(Source::values($this->user())),
                ],
                'active' => 'required|boolean',
            ],
            'connectors.create' => ['name' => 'required|string|max:100', 'source' => ['required', Rule::in(\App\Services\WorkspaceRegistry::codes($this->user(), true))]],
            default => [],
        };
    }
}
