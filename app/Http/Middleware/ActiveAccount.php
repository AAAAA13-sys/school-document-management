<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->user()?->active || ($request->session()->has('auth_version') && (int) $request->session()->get('auth_version') !== (int) $request->user()->auth_version)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/login')->withErrors(['email' => 'Your account is inactive. Contact your administrator.']);
        }

        return $next($request);
    }
}
