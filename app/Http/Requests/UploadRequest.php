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
        $merge = [];
        $submissionKey = $this->header('Idempotency-Key', $this->input('submission_key'));
        if ($submissionKey) {
            $merge['submission_key'] = $submissionKey;
        } elseif ($this->is('api/*')) {
            $ref = (string) $this->input('applicant_ref', '');
            $cat = (string) $this->input('category', '');
            $sha = (string) $this->input('sha256', '');
            $merge['submission_key'] = 'gen-'.hash('sha256', $ref.'|'.$cat.'|'.$sha);
        }

        if (! $this->filled('title')) {
            $ref = $this->input('applicant_ref');
            $cat = $this->input('category');
            if ($ref || $cat || $this->hasFile('file')) {
                $merge['title'] = trim(($ref ? $ref.' - ' : '').($cat ?? ($this->file('file')?->getClientOriginalName() ?? 'Document')));
            }
        }

        if (! $this->filled('classification') && $this->has('confidential')) {
            $val = $this->input('confidential');
            $merge['classification'] = ($val === true || $val === 1 || $val === '1' || $val === 'true') ? 'Confidential' : 'Internal';
        }

        if ($this->is('api/*')) {
            $this->request->remove('source');
            $this->query->remove('source');
        }

        if ($merge) {
            $this->merge($merge);
        }
    }

    public function rules(): array
    {
        $new = ! $this->route('document');

        $sources = $new ? Source::values($this->user()) : Access::scope(DB::table('workspaces'), $this->user() ?? $this->attributes->get('connector'))->pluck('code')->all();

        return ['file' => ['required', 'file', 'max:10240'],
            'title' => [$new ? 'required' : 'sometimes', 'string', 'max:200'], 'source' => [$this->is('api/*') ? 'prohibited' : 'required', 'in:'.implode(',', $sources)],
            'classification' => [$new ? 'required' : 'sometimes', 'in:Internal,Confidential,Restricted'], 'folder_id' => 'nullable|uuid',
            'subject' => 'nullable|string|max:200', 'reference' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100',
            'revision' => [$new ? 'sometimes' : ($this->is('api/*') ? 'sometimes' : 'required'), 'integer', 'min:1'], 'submission_key' => 'required|string|max:100',
            'confirm_duplicate' => 'sometimes|boolean',
            'force_new_version' => 'sometimes|boolean'];
    }
}
