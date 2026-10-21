<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Advisory move warning: the destination folder would grant access to more people.
 * The owner may repeat the move with confirm_move=true.
 */
class AccessChangeException extends HttpException
{
    public function __construct(public readonly array $access)
    {
        parent::__construct(409, 'Moving this document would give '.$access['gained'].' more '
            .($access['gained'] === 1 ? 'person' : 'people').' access through the destination folder.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'code' => 'access_change',
                'message' => $this->getMessage(),
                'access' => $this->access,
            ], 409);
        }

        return back()->withErrors(['folder_id' => $this->getMessage().' Confirm the move to continue.']);
    }
}
