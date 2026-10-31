<?php

namespace App\Http\Controllers;

use App\Models\AccountInvitation;
use App\Models\User;
use App\Services\Access;
use App\Services\HierarchicalInvitationService as InvitationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class InvitationController extends Controller
{
    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor->isSuperAdmin() || $actor->isAdmin(), 403);
        $invitations = AccountInvitation::query()
            ->where('school_id', $actor->school_id)
            ->where('campus', $actor->campus)
            ->when(! $actor->isSuperAdmin(), fn ($q) => $q->where('requested_by', $actor->id))
            ->with(['requester:id,name,email', 'reviewer:id,name,email'])
            ->latest()
            ->paginate(30);

        if ($request->expectsJson()) {
            return response()->json($invitations);
        }

        return view('invitations.index', compact('invitations'));
    }

    public function store(Request $request, InvitationService $service)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255', 'regex:/^[\pL\s\-\'.]+$/u'],
            'email' => 'required|email|max:255',
            'role' => 'required|in:staff,admin,super_admin',
            'source' => 'nullable|string|max:50',
        ], [
            'name.regex' => 'The full name may only contain letters, spaces, hyphens, periods, and apostrophes.',
        ]);
        $id = $service->request($request->user(), $data);
        $status = 'delivered';
        $message = 'Invitation email sent.';

        try {
            $service->deliver($request->user(), $id);
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 503) {
                $status = 'approved';
                $message = 'Invitation created. Outbound email is not configured or failed to deliver; invitation can be sent once mail transport is active.';
            } else {
                throw $e;
            }
        } catch (\Throwable $e) {
            $status = 'approved';
            $message = 'Invitation created. Outbound email is not configured or failed to deliver; invitation can be sent once mail transport is active.';
        }

        if ($request->expectsJson()) {
            return response()->json([
                'id' => $id,
                'status' => $status,
                'message' => $message,
            ], 201);
        }

        return back()->with('status', $message);
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
        $status = 'delivered';
        $message = 'Invitation email sent; previous invitation links are invalid.';

        try {
            $service->deliver($request->user(), $invitation);
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() === 503) {
                $status = 'approved';
                $message = 'Invitation approved. Outbound email is not configured or failed to deliver; invitation can be sent once mail transport is active.';
            } else {
                throw $e;
            }
        } catch (\Throwable $e) {
            $status = 'approved';
            $message = 'Invitation approved. Outbound email is not configured or failed to deliver; invitation can be sent once mail transport is active.';
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => $status,
                'message' => $status === 'delivered' ? 'Invitation email sent.' : $message,
            ]);
        }

        return back()->with('status', $message);
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

    public function form(Request $request, string $token)
    {
        $row = DB::table('account_invitations')
            ->where('token_hash', hash('sha256', $token))
            ->where('status', 'sent')
            ->where('expires_at', '>', now())
            ->first() ?? abort(410, 'Invitation expired or already used.');

        if ($request->expectsJson()) {
            return response()->json([
                'email' => $row->email,
                'name' => $row->name,
                'role' => $row->role,
                'source' => $row->source,
                'expires_at' => $row->expires_at,
            ]);
        }

        return response()->view('invitations.password', ['token' => $token, 'email' => $row->email, 'mode' => 'invite'])->header('Referrer-Policy', 'same-origin')->header('Cache-Control', 'no-store');
    }

    public function accept(Request $request, string $token, InvitationService $service)
    {
        $validator = Validator::make($request->all(), [
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            return redirect('/invitations/accept/'.$token)
                ->withErrors($validator)
                ->withInput();
        }

        $data = $validator->validated();
        $user = $service->accept($token, $data['password']);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Account activated. Sign in with your chosen password.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                ],
            ]);
        }

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
            $baseUrl = rtrim(config('app.url'), '/');
            $resetUrl = $baseUrl . '/reset-password/' . $token . '?email=' . rawurlencode($user->email);
            Mail::send(
                ['emails.password-reset', 'emails.password-reset-text'],
                [
                    'user' => $user,
                    'email' => $user->email,
                    'resetUrl' => $resetUrl,
                    'expiresIn' => config('auth.passwords.users.expire', 60),
                ],
                function ($message) use ($user) {
                    $message->to($user->email)->subject('Reset your DMS password · PLP Documents');
                }
            );
            Access::audit($user, 'account.password_reset_requested');
        });

        return back()->with('status', 'If an active account matches, a password reset email has been sent.');
    }

    public function resetForm(Request $request, string $token)
    {
        return response()->view('invitations.password', ['token' => $token, 'email' => $request->query('email'), 'mode' => 'reset'])->header('Referrer-Policy', 'same-origin')->header('Cache-Control', 'no-store');
    }

    public function reset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $token = $request->input('token');
            $email = $request->input('email');
            $target = $token ? '/reset-password/'.$token.($email ? '?email='.rawurlencode($email) : '') : '/forgot-password';

            return redirect($target)
                ->withErrors($validator)
                ->withInput($request->except('password', 'password_confirmation'));
        }

        $data = $validator->validated();
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
