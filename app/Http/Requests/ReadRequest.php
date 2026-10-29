<?php

namespace App\Http\Requests;

use App\Services\WorkspaceRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'layout' => 'nullable|in:list,grid', 'source' => ['nullable', Rule::in(WorkspaceRegistry::codes($this->user()))],
            'record_type' => 'nullable|string|max:100', 'record_id' => 'nullable|string|max:100'];
    }
}
