<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(LoginRequest $request)
    {
        if (! Auth::attempt($request->safe()->only(['email', 'password']) + ['active' => true])) {
            return back()->withErrors(['email' => 'The sign-in details are invalid or the account is inactive.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        $request->session()->put('auth_version', $request->user()->auth_version);
        Access::audit($request->user(), 'account.signed_in');

        return redirect()->intended(in_array($request->user()->role, ['super_admin', 'admin']) ? '/admin' : '/workspaces/'.strtolower($request->user()->source));
    }

    public function logout(Request $request)
    {
        Access::audit($request->user(), 'account.signed_out');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
