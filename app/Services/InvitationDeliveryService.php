<?php

namespace App\Services;

use App\Mail\EmployeeInvitationMail;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

class InvitationDeliveryService
{
    public function __construct(private InvitationService $invitations) {}

    public function unavailableReason(): ?string
    {
        if (config('invitations.dispatch_enabled') !== true) {
            return 'Invitation sending is disabled until registration is available.';
        }
        $mailer = config('mail.default');
        if (config('mail.mailers.'.$mailer.'.transport') !== 'smtp') {
            return 'Invitation sending requires the SMTP mailer. Log and fallback mailers are not delivery.';
        }
        if (! $this->validBaseUrl()) {
            return 'Invitation sending requires a valid HTTPS application URL (loopback HTTP is allowed locally).';
        }
        // Mail::fake is an explicit test-only exception, never a deployment gate bypass.
        if (! (app()->environment('testing') && Mail::isFake())) {
            foreach (['invitations.accept' => 'GET', 'invitations.register' => 'POST'] as $name => $method) {
                $route = Route::getRoutes()->getByName($name);
                if (! $route || $route->uri() !== 'invitations/{token}' || ! in_array($method, $route->methods(), true)) {
                    return 'Invitation sending is disabled because the registration destination is not available.';
                }
            }
        }

        return null;
    }

    public function createAndSend(User $actor, array $attributes): EmployeeInvitation
    {
        $this->assertReady();
        $issued = $this->invitations->create($actor, $attributes);

        return $this->send($actor, $issued);
    }

    public function resend(User $actor, string $id): EmployeeInvitation
    {
        $this->assertReady();

        return $this->withDeliveryLock($id, function () use ($actor, $id) {
            return $this->deliver($actor, $this->invitations->rotateToken($actor, $id));
        });
    }

    private function send(User $actor, #[SensitiveParameter] IssuedInvitation $issued): EmployeeInvitation
    {
        return $this->withDeliveryLock($issued->invitation->id, fn () => $this->deliver($actor, $issued));
    }

    private function withDeliveryLock(string $id, callable $operation): EmployeeInvitation
    {
        $lock = Cache::lock('invitation-delivery:'.$id, 120);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['invitation_delivery' => 'An invitation email is already being processed. Try again shortly.']);
        }
        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    private function deliver(User $actor, #[SensitiveParameter] IssuedInvitation $issued): EmployeeInvitation
    {
        $this->assertReady();
        try {
            $invitation = DB::transaction(function () use ($actor, $issued) {
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $invitation = EmployeeInvitation::whereKey($issued->invitation->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('manage', $invitation);
                $this->invitations->validateToken($issued->revealToken());
                if (! hash_equals($invitation->token_hash, $issued->invitation->token_hash)) {
                    throw ValidationException::withMessages(['invitation_delivery' => 'This invitation has changed. Please resend it.']);
                }
                $invitation->forceFill(['delivery_status' => 'sending', 'delivery_attempted_at' => now(), 'sent_at' => null, 'delivery_error_code' => null])->save();
                Access::audit($actor, 'invitation.delivery_attempted', details: ['invitation_id' => $invitation->id], source: $invitation->source);

                return $invitation;
            }, 3);
            $successful = false;
            try {
                $message = new EmployeeInvitationMail($invitation->email, $invitation->expires_at,
                    rtrim(config('app.url'), '/').'/invitations/'.rawurlencode($issued->revealToken()));
                $sent = Mail::mailer(config('mail.default'))->to($invitation->email)->send($message);
                $successful = $sent !== null || (app()->environment('testing') && Mail::isFake());
            } catch (Throwable) {
                // SMTP exceptions can include message bodies, links and credentials.
                // Never log, report, chain or persist the provider exception.
            }
            DB::transaction(function () use ($actor, $invitation, $successful) {
                $current = EmployeeInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
                // A concurrently rotated token must never inherit this attempt's status.
                if (! hash_equals($current->token_hash, $invitation->token_hash)) {
                    return;
                }
                $current->forceFill([
                    'delivery_status' => $successful ? 'sent' : 'failed',
                    'sent_at' => $successful ? now() : null,
                    'delivery_error_code' => $successful ? null : 'smtp_send_failed',
                ])->save();
                Access::audit($actor, $successful ? 'invitation.email_submitted' : 'invitation.delivery_failed', details: ['invitation_id' => $current->id], source: $current->source);
            }, 3);

            return $invitation->fresh();
        } catch (QueryException) {
            // SMTP and the database cannot commit atomically; never claim success
            // when delivery tracking could not be saved, or leak query bindings.
            throw new \RuntimeException('Invitation delivery tracking is unavailable. Check the invitation before resending.');
        }
    }

    private function assertReady(): void
    {
        if ($reason = $this->unavailableReason()) {
            throw ValidationException::withMessages(['invitation_delivery' => $reason]);
        }
    }

    private function validBaseUrl(): bool
    {
        $url = config('app.url');
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        return ($parts['scheme'] ?? '') === 'https'
            || (app()->environment('local', 'testing') && ($parts['scheme'] ?? '') === 'http'
                && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true));
    }
}
