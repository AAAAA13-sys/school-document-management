<?php

namespace App\Http\Requests;

use App\Enums\Source;
use App\Services\Access;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

class UploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->route('document') && ! $this->is('api/*')) {
            $doc = Access::document($this->user(), (string) $this->route('document'));

            return $this->user()->can('document.edit', $doc);
        }
        $source = $this->input('source');
        if ($this->is('api/*') || ! is_string($source)) {
            return true;
        }
        $registered = Access::scope(DB::table('workspaces'), $this->user())->where('code', $source)->exists();

        return ! $registered || $this->user()->canAccessSource($source);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['submission_key' => $this->header('Idempotency-Key', $this->input('submission_key'))]);
    }

    public function rules(): array
    {
        $new = ! $this->route('document');

        $sources = $new ? Source::values($this->user()) : Access::scope(DB::table('workspaces'), $this->user() ?? $this->attributes->get('connector'))->pluck('code')->all();

        return ['file' => ['required', 'file', 'max:10240'],
            'title' => [$new ? 'required' : 'sometimes', 'string', 'max:200'], 'source' => [$this->is('api/*') ? 'prohibited' : 'required', 'in:'.implode(',', $sources)],
            'classification' => [$new ? 'required' : 'sometimes', 'in:Internal,Confidential,Restricted'], 'folder_id' => 'nullable|uuid',
            'subject' => 'nullable|string|max:200', 'reference' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100',
            'revision' => [$new ? 'sometimes' : 'required', 'integer', 'min:1'], 'submission_key' => 'required|string|max:100',
            'confirm_duplicate' => 'sometimes|boolean',
            'force_new_version' => 'sometimes|boolean'];
    }
}
