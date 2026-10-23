<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Advisory duplicate warning / conflict exception.
 * Includes the existing document_id in the 409 response.
 */
class DuplicateFileException extends HttpException
{
    public function __construct(
        public readonly array $matches = [],
        public readonly ?string $documentId = null
    ) {
        parent::__construct(409, 'An identical file already exists in this workspace.');
    }

    public function getDocumentId(): ?string
    {
        return $this->documentId ?? ($this->matches[0]['document_id'] ?? null);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        $docId = $this->getDocumentId();

        if ($request->expectsJson() || $request->is('api/*') || $request->wantsJson() || ! $request->hasHeader('referer')) {
            return response()->json([
                'code' => 'duplicate_file',
                'message' => $this->getMessage(),
                'document_id' => $docId,
                'duplicates' => $this->matches,
            ], 409);
        }

        return back()->withErrors([
            'file' => $this->getMessage().' Open existing document: '.$docId.'.',
        ])->with('document_id', $docId);
    }
}
