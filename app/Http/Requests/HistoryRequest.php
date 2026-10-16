<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['event_id' => 'required|string|max:100', 'record_type' => 'required|string|max:100', 'record_id' => 'required|string|max:100',
            'revision' => 'required|integer|min:1|max:2147483647', 'operation' => 'required|in:upsert,delete', 'payload' => 'present|array',
            'source' => 'prohibited', 'school_id' => 'prohibited', 'campus' => 'prohibited'];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (strlen(json_encode($this->input('payload'))) > 262144 || strlen($this->getContent()) > 270336) {
                $validator->errors()->add('payload', 'History payload exceeds 256 KiB.');
            }
        }];
    }
}
