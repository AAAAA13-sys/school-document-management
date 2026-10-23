<?php

namespace App\Http\Controllers;

use App\Http\Requests\AccountRequest;
use App\Models\User;
use App\Services\Access;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $source = $user->source ?? 'ALL';

        if ($request->expectsJson()) {
            return response()->json([
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'role_label' => config('dms.role_labels.'.$user->role, ucfirst(str_replace('_', ' ', $user->role))),
                    'source' => $user->source,
                    'sources' => $user->sources(),
                    'school_id' => $user->school_id,
                    'campus' => $user->campus,
                    'active' => (bool) $user->active,
                ],
                'home_path' => $user->homePath(),
            ]);
        }

        return view('account', ['source' => $source, 'section' => 'account']);
    }

    public function password(AccountRequest $request)
    {
        DB::transaction(function () use ($request) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($request->validated('current_password'), $user->password)) {
                throw ValidationException::withMessages(['current_password' => 'The current password is incorrect.']);
            }
            $user->password = $request->validated('password');
            $user->remember_token = Str::random(60);
            $user->auth_version++;
            $user->save();
            $request->session()->put('auth_version', $user->auth_version);
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
            Access::audit($user, 'account.password_changed');
        });
        $request->session()->regenerate();

        return back()->with('status', 'Password changed. Other sessions have been signed out.');
    }
}
