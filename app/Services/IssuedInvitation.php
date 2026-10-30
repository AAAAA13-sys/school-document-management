<?php

namespace App\Services;

use App\Models\EmployeeInvitation;
use JsonSerializable;
use SensitiveParameter;

/** One-time credential handoff to a future delivery layer. Never persist this object. */
final class IssuedInvitation implements JsonSerializable
{
    public function __construct(
        public readonly EmployeeInvitation $invitation,
        #[SensitiveParameter] private readonly string $token,
    ) {}

    public function revealToken(): string
    {
        return $this->token;
    }

    public function jsonSerialize(): array
    {
        return $this->invitation->toArray();
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Invitation credentials must not be serialized.');
    }
}
