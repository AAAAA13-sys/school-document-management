<?php

namespace App\Http\Controllers;

use App\Mail\EmployeeInvitationMail;
use App\Models\EmployeeInvitation;
use App\Services\Access;
use App\Services\InvitationDeliveryService;
use App\Services\InvitationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AdminInvitationController extends Controller
{
    public function store(Request $request, InvitationDeliveryService $delivery)
    {
        Gate::authorize('create', EmployeeInvitation::class);
        try {
            $invitation = $delivery->createAndSend($request->user(), $request->except('_token'));

            return $this->feedback($invitation);
        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return redirect('/admin/accounts')->withErrors(['invitation_delivery' => 'Invitation processing could not be completed. Check the history before trying again.']);
        }
    }

    public function resend(Request $request, string $invitation, InvitationDeliveryService $delivery)
    {
        $row = $this->scoped($request, $invitation);
        try {
            return $this->feedback($delivery->resend($request->user(), $row->id));
        } catch (RuntimeException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }

            return redirect('/admin/accounts')->withErrors(['invitation_delivery' => 'Resend processing could not be completed. Check the history before trying again.']);
        }
    }

    public function revoke(Request $request, string $invitation, InvitationService $service)
    {
        $row = $this->scoped($request, $invitation);
        $request->validate(['confirm_revoke' => 'required|accepted']);
        $service->revoke($request->user(), $row->id);

        return redirect('/admin/accounts')->with('status', 'Invitation revoked. Its registration link is no longer valid.');
    }

    public function preview()
    {
        Gate::authorize('create', EmployeeInvitation::class);
        // No real invitation, recipient, token or registration destination is used.
        $mail = new EmployeeInvitationMail('employee@example.test', CarbonImmutable::now()->addDay(), 'https://example.invalid/invitations/preview-not-valid');

        return response($mail->render())->header('Cache-Control', 'no-store, private')
            ->header('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'self'; form-action 'none'")
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function scoped(Request $request, string $id): EmployeeInvitation
    {
        Gate::authorize('create', EmployeeInvitation::class);
        $row = Access::administrationScope(EmployeeInvitation::query()->getQuery(), $request->user())->where('id', $id)->first() ?? abort(404);
        $invitation = (new EmployeeInvitation)->newFromBuilder((array) $row);
        Gate::authorize('manage', $invitation);

        return $invitation;
    }

    private function feedback(EmployeeInvitation $invitation)
    {
        if ($invitation->delivery_status !== 'sent') {
            return redirect('/admin/accounts')->withErrors(['invitation_delivery' => 'Invitation saved, but email delivery failed or could not be confirmed. Check SMTP configuration and use Resend to issue a new link.']);
        }

        return redirect('/admin/accounts')->with('status', 'Invitation email submitted to SMTP. Inbox delivery is not guaranteed.');
    }
}
