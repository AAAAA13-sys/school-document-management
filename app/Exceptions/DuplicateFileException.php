<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Advisory duplicate warning. The upload is not rejected outright: the caller may
 * repeat the request with confirm_duplicate=true to keep an intentional copy.
 */
class DuplicateFileException extends HttpException
{
    public function __construct(public readonly array $matches)
    {
        parent::__construct(409, 'An identical file already exists in a document you can access.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'code' => 'duplicate_file',
                'message' => $this->getMessage(),
                'duplicates' => $this->matches,
            ], 409);
        }

        return back()->withErrors([
            'file' => $this->getMessage().' Open the existing document, or confirm a separate copy to continue.',
        ]);
    }
}
