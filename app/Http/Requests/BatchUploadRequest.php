<?php

namespace App\Http\Requests;

use App\Enums\Source;
use Illuminate\Foundation\Http\FormRequest;

class BatchUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->active === true;
    }

    public function rules(): array
    {
        return ['files' => 'required|array|list|min:1|max:50', 'files.*' => ['required', 'file', 'max:10240'],
            'paths' => 'required|array|list|min:1|max:50', 'paths.*' => 'required|string|max:1500',
            'source' => ['required', 'in:'.implode(',', Source::values($this->user()))], 'folder_id' => 'nullable|uuid', 'classification' => 'required|in:Internal,Confidential,Restricted', 'submission_key' => 'required|string|max:100',
            'confirm_duplicate' => 'sometimes|boolean'];
    }

    public function after(): array
    {
        return [function ($validator) {
            $files = $this->file('files', []);
            $paths = $this->input('paths', []);
            if (! is_array($files) || ! is_array($paths) || count($files) !== count($paths)) {
                $validator->errors()->add('paths', 'Each file needs its relative folder path.');

                return;
            }
            foreach ($paths as $i => $path) {
                if (! is_string($path)) {
                    continue;
                }
                $parts = explode('/', str_replace('\\', '/', $path));
                if (count($parts) > 12 || count($parts) < 2 || end($parts) !== ($files[$i]?->getClientOriginalName() ?? null)) {
                    $validator->errors()->add('paths', 'Choose a folder with at most 11 nested levels.');

                    continue;
                }
                foreach ($parts as $part) {
                    if (in_array($part, ['', '.', '..']) || mb_strlen($part) > 150 || preg_match('/[\x00-\x1F:]/', $part)) {
                        $validator->errors()->add('paths', 'Folder names must be safe relative paths, up to 150 characters per segment.');
                    }
                }
            }
            if (count($paths) !== count(array_unique(array_filter($paths, 'is_string')))) {
                $validator->errors()->add('paths', 'Duplicate paths are not allowed.');
            }
        }];
    }
}
