<?php

namespace App\Http\Middleware;

use Illuminate\Session\Middleware\StartSession;

class InvitationSession extends StartSession
{
    protected function storeCurrentUrl($request, $session)
    {
        // Retain normal sessions and CSRF, without saving bearer URLs as history.
        if (! $request->is('invitations/*')) {
            parent::storeCurrentUrl($request, $session);
        }
    }
}
