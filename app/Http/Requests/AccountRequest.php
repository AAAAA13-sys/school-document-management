<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class AccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->active === true;
    }

    public function rules(): array
    {
        return ['current_password' => 'required|current_password', 'password' => ['required', 'string', 'max:255', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()->numbers()->symbols()]];
    }
}
