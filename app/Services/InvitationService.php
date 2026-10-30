<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\Source;
use App\Exceptions\InvitationUnavailableException;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

/** Staff onboarding foundation (FR-05/NFR-01); deliberately has no HTTP or mail entry point. */
class InvitationService
{
    public function create(User $actor, array $attributes): IssuedInvitation
    {
        try {
            return DB::transaction(function () use ($actor, $attributes) {
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('create', EmployeeInvitation::class);
                $data = Validator::make($this->normalize($attributes), [
                    'email' => 'required|email|max:255',
                    'source' => ['required', Rule::in(Access::scope(DB::table('workspaces'), $actor)->where('status', 'active')->pluck('code')->all())],
                ] + $this->protectedFields())->validate();
                Gate::forUser($actor)->authorize('accounts.source', $data['source']);
                $this->ensureAvailableEmail($data['email']);
                $this->releaseExpiredReservations($data['email']);
                $this->ensureNoPendingInvitation($data['email']);

                $token = bin2hex(random_bytes(32));
                $invitation = new EmployeeInvitation;
                $invitation->forceFill([
                    'email' => $data['email'], 'pending_email' => $data['email'],
                    'role' => Role::Staff->value, 'source' => $data['source'],
                    'school_id' => $actor->school_id, 'campus' => $actor->campus,
                    'invited_by' => $actor->id, 'token_hash' => hash('sha256', $token),
                    'expires_at' => $this->expiration(),
                ])->save();
                $this->audit($actor, 'invitation.created', $invitation);

                return new IssuedInvitation($invitation, $token);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // Do not propagate SQL/bindings containing credential hashes.
            throw ValidationException::withMessages(['email' => 'An account or pending invitation already exists for this email.']);
        } catch (QueryException) {
            throw new \RuntimeException('Invitation storage is unavailable.');
        }
    }

    /** Read-only validation: opening a link never consumes it. */
    public function validateToken(#[SensitiveParameter] string $token): EmployeeInvitation
    {
        try {
            $invitation = $this->findToken($token);
            $this->ensureUsable($invitation);
            $this->ensureIssuerAuthority($invitation, $invitation->inviter);
            $this->ensureAvailableEmail($invitation->email);

            return $invitation;
        } catch (QueryException) {
            // No previous exception: query bindings can contain token hashes.
            throw new \RuntimeException('Invitation storage is unavailable.');
        }
    }

    public function revoke(User $actor, string $id): EmployeeInvitation
    {
        try {
            return DB::transaction(function () use ($actor, $id) {
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $invitation = $this->manageable($actor, $id);
                if ($invitation->accepted_at !== null) {
                    $this->invalidInvitation();
                }
                if ($invitation->revoked_at === null) {
                    $invitation->forceFill(['revoked_at' => now(), 'pending_email' => null])->save();
                    $this->audit($actor, 'invitation.revoked', $invitation);
                }

                return $invitation;
            }, 3);
        } catch (QueryException) {
            throw new \RuntimeException('Invitation storage is unavailable.');
        }
    }

    /** Rotates credentials and extends expiry; does not send mail or revive revoked invitations. */
    public function rotateToken(User $actor, string $id): IssuedInvitation
    {
        try {
            return DB::transaction(function () use ($actor, $id) {
                $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $invitation = $this->manageable($actor, $id);
                if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
                    $this->invalidInvitation();
                }
                $this->ensureIssuerAuthority($invitation, $invitation->inviter);
                $this->ensureAvailableEmail($invitation->email);
                $this->releaseExpiredReservations($invitation->email, $invitation->id);
                $this->ensureNoPendingInvitation($invitation->email, $invitation->id);
                $token = bin2hex(random_bytes(32));
                $invitation->forceFill([
                    'token_hash' => hash('sha256', $token), 'expires_at' => $this->expiration(),
                    'pending_email' => $invitation->email,
                    'delivery_status' => 'not_sent', 'delivery_attempted_at' => null,
                    'sent_at' => null, 'delivery_error_code' => null,
                ])->save();
                $this->audit($actor, 'invitation.rotated', $invitation);

                return new IssuedInvitation($invitation, $token);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'An account or pending invitation already exists for this email.']);
        } catch (QueryException) {
            throw new \RuntimeException('Invitation storage is unavailable.');
        }
    }

    public function accept(#[SensitiveParameter] string $token, #[SensitiveParameter] array $attributes): User
    {
        try {
            return DB::transaction(function () use ($token, $attributes) {
                $candidate = $this->findToken($token);
                // Use the same user-before-invitation lock order as administration.
                $issuer = User::whereKey($candidate->invited_by)->lockForUpdate()->first();
                $invitation = EmployeeInvitation::whereKey($candidate->id)->lockForUpdate()->first();
                if (! $invitation || ! hash_equals($invitation->token_hash, hash('sha256', $token))) {
                    $this->invalidInvitation();
                }
                $this->ensureUsable($invitation);
                $this->ensureIssuerAuthority($invitation, $issuer);
                $this->ensureAvailableEmail($invitation->email);
                $data = Validator::make($this->normalize($attributes), [
                    'name' => 'required|string|max:255',
                    'email' => ['sometimes', 'required', 'email', Rule::in([$invitation->email])],
                    'password' => ['bail', 'required', 'string', 'max:255', 'confirmed', function ($attribute, $value, $fail) {
                        if (str_contains($value, "\0")) {
                            $fail('The password contains an unsupported character.');
                        } elseif (Hash::getDefaultDriver() === 'bcrypt' && strlen($value) > 72) {
                            $fail('The password must not exceed 72 bytes with the current password hashing configuration.');
                        }
                    }, Password::min(12)->mixedCase()->numbers()->symbols()],
                ] + $this->protectedFields(true))->validate();
                $user = new User;
                $user->name = $data['name'];
                // Treat all submitted passwords as plaintext, even strings that
                // look like hashes (the User cast otherwise accepts pre-hashed values).
                $user->password = Hash::make($data['password']);
                $user->email = $invitation->email;
                $user->role = Role::Staff->value;
                $user->source = $invitation->source;
                $user->school_id = $invitation->school_id;
                $user->campus = $invitation->campus;
                $user->active = true;
                // Email verification remains unchanged until that policy is agreed.
                $user->save();
                WorkspaceRegistry::assign($user, $user->source);
                $invitation->forceFill([
                    'accepted_at' => now(), 'accepted_user_id' => $user->id, 'pending_email' => null,
                ])->save();
                $this->audit($issuer, 'invitation.accepted', $invitation);
                Access::audit($user, 'account.created', details: ['invitation_id' => $invitation->id], source: $invitation->source);

                return $user;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'An account already exists for this email.']);
        } catch (QueryException) {
            throw new \RuntimeException('Invitation storage is unavailable.');
        }
    }

    private function findToken(#[SensitiveParameter] string $token): EmployeeInvitation
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $token)) {
            $this->invalidInvitation();
        }
        $invitation = EmployeeInvitation::where('token_hash', hash('sha256', $token))->first();
        if (! $invitation) {
            $this->invalidInvitation();
        }

        return $invitation;
    }

    private function manageable(User $actor, string $id): EmployeeInvitation
    {
        Gate::forUser($actor)->authorize('create', EmployeeInvitation::class);
        $query = Access::administrationScope(EmployeeInvitation::query()->getQuery(), $actor);
        $row = $query->where('id', $id)->lockForUpdate()->first() ?? abort(404);
        $invitation = (new EmployeeInvitation)->newFromBuilder((array) $row);
        Gate::forUser($actor)->authorize('manage', $invitation);

        return $invitation;
    }

    private function ensureUsable(EmployeeInvitation $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            $this->invalidInvitation('used');
        }
        if ($invitation->revoked_at !== null) {
            $this->invalidInvitation('revoked');
        }
        if ($invitation->hasExpired()) {
            $this->invalidInvitation('expired');
        }
    }

    private function ensureIssuerAuthority(EmployeeInvitation $invitation, ?User $issuer): void
    {
        $policy = new UserPolicy;
        if (! $issuer || ! $issuer->active || $invitation->role !== Role::Staff->value
            || ! in_array($invitation->source, Source::values($issuer), true)
            || $issuer->school_id !== $invitation->school_id || $issuer->campus !== $invitation->campus
            || ! $policy->createRole($issuer, Role::Staff->value) || ! $policy->createSource($issuer, $invitation->source)) {
            $this->invalidInvitation();
        }
    }

    private function ensureAvailableEmail(string $email): void
    {
        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'An account already exists for this email.']);
        }
    }

    private function ensureNoPendingInvitation(string $email, ?string $except = null): void
    {
        if (EmployeeInvitation::where('pending_email', $email)->when($except, fn ($q) => $q->where('id', '!=', $except))->exists()) {
            throw ValidationException::withMessages(['email' => 'A pending invitation already exists for this email.']);
        }
    }

    private function releaseExpiredReservations(string $email, ?string $except = null): void
    {
        EmployeeInvitation::where('pending_email', $email)->where('expires_at', '<=', now())
            ->when($except, fn ($q) => $q->where('id', '!=', $except))->update(['pending_email' => null]);
    }

    private function normalize(#[SensitiveParameter] array $data): array
    {
        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }
        if (isset($data['source']) && is_string($data['source'])) {
            $data['source'] = strtoupper(trim($data['source']));
        }

        return $data;
    }

    private function protectedFields(bool $accepting = false): array
    {
        $fields = ['role', 'school_id', 'campus', 'active', 'invited_by', 'token_hash', 'pending_email', 'expires_at', 'accepted_at', 'accepted_user_id', 'revoked_at', 'delivery_status', 'delivery_attempted_at', 'sent_at', 'delivery_error_code'];
        if ($accepting) {
            $fields[] = 'source';
        }

        return array_fill_keys($fields, 'prohibited');
    }

    private function expiration(): Carbon
    {
        $hours = config('invitations.expires_in_hours');
        if (! is_int($hours) || $hours < 1) {
            throw new \LogicException('Invitation expiration must be a positive number of hours.');
        }

        return now()->addHours($hours);
    }

    private function audit(User $actor, string $action, EmployeeInvitation $invitation): void
    {
        Access::audit($actor, $action, details: ['invitation_id' => $invitation->id, 'account_id' => $invitation->accepted_user_id], source: $invitation->source);
    }

    private function invalidInvitation(string $reason = 'invalid'): never
    {
        throw new InvitationUnavailableException($reason);
    }
}
