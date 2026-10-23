<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['after' => 'sometimes|integer|min:0|max:9223372036854775806', 'limit' => 'sometimes|integer|min:1|max:100',
            'classification' => 'nullable|in:Internal,Confidential,Restricted', 'q' => 'nullable|string|max:200', 'folder' => 'nullable|uuid', 'view' => 'nullable|in:all,mine,shared,starred,trash',
            'layout' => 'nullable|in:list,grid', 'source' => ['nullable', \Illuminate\Validation\Rule::in(\App\Services\WorkspaceRegistry::codes($this->user()))],
            'record_type' => 'nullable|string|max:100', 'record_id' => 'nullable|string|max:100'];
    }
}
