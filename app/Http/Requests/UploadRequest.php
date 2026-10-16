<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['submission_key' => $this->header('Idempotency-Key', $this->input('submission_key'))]);
    }

    public function rules(): array
    {
        $new = ! $this->route('document');

        return ['file' => ['required', 'file', 'max:10240'],
            'title' => [$new ? 'required' : 'sometimes', 'string', 'max:200'], 'source' => [$this->is('api/*') ? 'prohibited' : 'required', 'in:EMS,PMS,ES,OAS,DMS'],
            'classification' => [$new ? 'required' : 'sometimes', 'in:Internal,Confidential,Restricted'], 'folder_id' => 'nullable|uuid',
            'subject' => 'nullable|string|max:200', 'reference' => 'nullable|string|max:100', 'category' => 'nullable|string|max:100',
            'revision' => [$new ? 'sometimes' : 'required', 'integer', 'min:1'], 'submission_key' => 'required|string|max:100'];
    }
}
