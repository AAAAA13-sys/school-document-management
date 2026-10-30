<?php

namespace App\Http\Controllers;

use App\Exceptions\InvitationUnavailableException;
use App\Services\InvitationService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

class InvitationRegistrationController extends Controller
{
    public function show(Request $request, #[SensitiveParameter] string $token, InvitationService $service)
    {
        try {
            return $this->form($request, $token, $service);
        } catch (InvitationUnavailableException $error) {
            return self::unavailable($error->reason, $error->reason === 'invalid' ? 404 : 410);
        } catch (ValidationException) {
            // Existing-account conflicts never disclose account details to guests.
            return self::unavailable('invalid', 409);
        } catch (Throwable) {
            return self::unavailable('unavailable', 503);
        }
    }

    public function register(Request $request, #[SensitiveParameter] string $token, InvitationService $service)
    {
        if ($request->user()) {
            return self::unavailable('signed_in', 409);
        }
        try {
            $service->accept($token, $request->except('_token'));

            // Never auto-login; the existing sign-in flow initializes auth_version.
            return redirect()->route('login')->with('registration_complete', true);
        } catch (InvitationUnavailableException $error) {
            return self::unavailable($error->reason, $error->reason === 'invalid' ? 404 : 410);
        } catch (ValidationException $error) {
            try {
                // Revalidate before redisplaying. Do not flash request data or redirect
                // to a token-bearing URL, and never return a password in HTML/JSON.
                return $this->form($request, $token, $service, $error->errors(), 422);
            } catch (InvitationUnavailableException $state) {
                return self::unavailable($state->reason, $state->reason === 'invalid' ? 404 : 410);
            } catch (ValidationException) {
                return self::unavailable('invalid', 409);
            } catch (Throwable) {
                return self::unavailable('unavailable', 503);
            }
        } catch (Throwable) {
            return self::unavailable('unavailable', 503);
        }
    }

    private function form(Request $request, #[SensitiveParameter] string $token, InvitationService $service, array $validationErrors = [], int $status = 200)
    {
        $invitation = $service->validateToken($token);

        return response()->view('invitations.register', [
            'invitedEmail' => $invitation->email,
            'assignedSource' => $invitation->source,
            'formAction' => route('invitations.register', ['token' => $token], false),
            'fullName' => is_string($request->input('name')) ? mb_substr($request->input('name'), 0, 255) : '',
            'validationErrors' => $validationErrors,
            'signedIn' => $request->user() !== null,
        ], $status);
    }

    public static function unavailable(string $state, int $status)
    {
        return response()->view('invitations.unavailable', ['state' => $state], $status);
    }
}
