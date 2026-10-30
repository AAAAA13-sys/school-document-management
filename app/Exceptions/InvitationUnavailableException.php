<?php

namespace App\Exceptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Safe lifecycle classification; contains no account, token or issuer data. */
class InvitationUnavailableException extends ValidationException
{
    public function __construct(public readonly string $reason = 'invalid')
    {
        parent::__construct(Validator::make([], []));
        $this->validator->errors()->add('invitation', 'This invitation is invalid or no longer available.');
    }
}
