<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $inactive = $user && (! $user->active
            || ($request->session()->has('auth_version')
                && (int) $request->session()->get('auth_version') !== (int) $user->auth_version));

        if (! $inactive) {
            return $next($request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $message = 'Your account is inactive. Contact your administrator.';
        $wantsMachineResponse = $request->expectsJson()
            || $request->is('api/*')
            || $request->is('files/*')
            || $request->routeIs('files.*');

        if ($wantsMachineResponse) {
            return response()->json(['message' => $message], 403);
        }

        return redirect('/login')->withErrors(['email' => $message]);
    }
}
