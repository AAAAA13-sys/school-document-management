<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Access;
use App\Services\InvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class InvitationController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->isSuperAdmin() || $actor->isAdmin(), 403);
        $invitations = DB::table('account_invitations')->where('school_id', $actor->school_id)->where('campus', $actor->campus)
            ->when(! $actor->isSuperAdmin(), fn ($q) => $q->where('requested_by', $actor->id))->latest()->paginate(30);

        if ($request->expectsJson()) {
            return response()->json($invitations);
        }

        return view('invitations.index', compact('invitations'));
    }

    public function store(Request $request, InvitationService $service)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => 'required|email|max:255', 'role' => 'required|in:staff,admin,super_admin', 'source' => 'nullable|string|max:50']);
        $id = $service->request($request->user(), $data);
        if ($request->user()->isSuperAdmin()) {
            $service->deliver($request->user(), $id);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $id,
                'status' => $request->user()->isSuperAdmin() ? 'delivered' : 'pending',
                'message' => $request->user()->isSuperAdmin() ? 'Invitation email sent.' : 'Staff invitation submitted for Super Admin approval.',
            ], 201);
        }

        return back()->with('status', $request->user()->isSuperAdmin() ? 'Invitation email sent.' : 'Staff invitation submitted for Super Admin approval.');
    }

    public function review(Request $request, string $invitation, InvitationService $service)
    {
        $data = $request->validate(['approve' => 'required|boolean', 'reason' => 'required|string|max:2000']);
        $service->review($request->user(), $invitation, (bool) $data['approve'], $data['reason']);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => (bool) $data['approve'] ? 'approved' : 'rejected',
                'message' => 'Invitation decision recorded.',
            ]);
        }

        return back()->with('status', 'Invitation decision recorded. Approved invitations can now be sent.');
    }

    public function send(Request $request, string $invitation, InvitationService $service)
    {
        $service->deliver($request->user(), $invitation);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'delivered',
                'message' => 'Invitation email sent.',
            ]);
        }

        return back()->with('status', 'Invitation email sent; previous invitation links are invalid.');
    }

    public function revoke(Request $request, string $invitation, InvitationService $service)
    {
        $service->revoke($request->user(), $invitation);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'revoked',
                'message' => 'Invitation revoked.',
            ]);
        }

        return back()->with('status', 'Invitation revoked.');
    }

    public function form(string $token)
    {
        $row = DB::table('account_invitations')->where('token_hash', hash('sha256', $token))->where('status', 'sent')->where('expires_at', '>', now())->first() ?? abort(410, 'Invitation expired or already used.');

        return response()->view('invitations.password', ['token' => $token, 'email' => $row->email, 'mode' => 'invite'])->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request, string $token, InvitationService $service)
    {
        $data = $request->validate(['password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols()]]);
        $service->accept($token, $data['password']);

        return redirect('/login')->with('status', 'Account activated. Sign in with your chosen password.');
    }

    public function forgot()
    {
        return view('invitations.forgot');
    }

    public function emailReset(Request $request, InvitationService $service)
    {
        $data = $request->validate(['email' => 'required|email']);
        $service->requireMail();
        Password::sendResetLink($data, function (User $user, string $token) {
            if (! $user->active) {
                return;
            }
            Mail::raw('Reset your DMS password: '.url('/reset-password/'.$token.'?email='.rawurlencode($user->email)), fn ($message) => $message->to($user->email)->subject('Reset your DMS password'));
            Access::audit($user, 'account.password_reset_requested');
        });

        return back()->with('status', 'If an active account matches, a password reset email has been sent.');
    }

    public function resetForm(Request $request, string $token)
    {
        return response()->view('invitations.password', ['token' => $token, 'email' => $request->query('email'), 'mode' => 'reset'])->header('Referrer-Policy', 'no-referrer')->header('Cache-Control', 'no-store');
    }

    public function reset(Request $request)
    {
        $data = $request->validate(['token' => 'required|string', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(12)->mixedCase()->numbers()->symbols()]]);
        $status = Password::reset($data, function (User $user, string $password) {
            abort_unless($user->active, 422, 'This password reset request is invalid.');
            DB::transaction(function () use ($user, $password) {
                $user->password = $password;
                $user->auth_version++;
                $user->setRememberToken(Str::random(60));
                $user->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                Access::audit($user, 'account.password_reset');
            });
        });

        return $status === Password::PASSWORD_RESET ? redirect('/login')->with('status', 'Password reset. Sign in again.') : back()->withErrors(['email' => __($status)]);
    }
}
