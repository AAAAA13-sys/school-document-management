<?php

namespace App\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use JsonSerializable;
use SensitiveParameter;

/** Synchronous only: the registration URL must never enter a queued payload. */
class EmployeeInvitationMail extends Mailable implements JsonSerializable
{
    public function __construct(
        protected string $invitedEmail,
        protected CarbonImmutable $expiresAt,
        #[SensitiveParameter] protected string $registrationUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your invitation to PLP Documents');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.employee-invitation',
            text: 'emails.employee-invitation-text',
            with: [
                'invitedEmail' => $this->invitedEmail,
                'expiresAt' => $this->expiresAt->utc()->format('F j, Y, H:i').' UTC',
                'registrationUrl' => $this->registrationUrl,
            ],
        );
    }

    public function jsonSerialize(): array
    {
        return ['type' => 'employee_invitation'];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Invitation mail must be sent synchronously.');
    }
}
