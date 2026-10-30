# Staff invitation backend — Batch 1

This foundation extends FR-05 account management and NFR-01 access control. It adds no routes, UI, mail sender, authentication stack or dependencies. Existing direct account creation and password recovery remain unchanged.

## Service contract

Resolve `App\Services\InvitationService` from the container:

- `create(User $actor, array $attributes): IssuedInvitation` accepts email and source only. Email is trimmed/lowercased and source normalized. Privileged fields are prohibited. Staff role and the actor's school/campus are assigned by the server.
- `validateToken(string $token): EmployeeInvitation` is read-only. It rejects malformed, expired, accepted, revoked or unauthorized-issuer invitations with a generic validation error.
- `revoke(User $actor, string $id): EmployeeInvitation` is idempotent for revoked invitations and rejects accepted invitations.
- `rotateToken(User $actor, string $id): IssuedInvitation` invalidates the old token and starts a new expiration period. Expired invitations may rotate if no replacement invitation/account exists; revoked/accepted invitations cannot rotate. This operation prepares resend but sends nothing.
- `accept(string $token, array $attributes): User` requires name, password and password_confirmation. Optional email must match the normalized invited email. Role/source/school/campus/active and invitation state are prohibited input. No login/session is created.

The issued result hides credentials from JSON/debug output and rejects PHP serialization. The future trusted delivery layer must explicitly call `revealToken()` once, use an HTTPS link, and avoid logging, serializing, flashing or persisting that raw token. The database stores only its SHA-256 hash. Invitation models hide hashes and reject mass assignment. Application audit events contain IDs and source, never token material. Database failures are sanitized without chaining query exceptions that could expose hash bindings.

## Authorization and atomicity

An active Admin may invite Staff in its existing permitted sources; an active Super Admin may use any of the four sources. Existing source-less legacy Admin authority is preserved through UserPolicy. School/campus always come from a freshly loaded actor. Out-of-scope management IDs return 404; unauthorized actors/actions are forbidden.

Acceptance rechecks the inviter's current active state, role and school/campus/source authority. A suspended, demoted or moved issuer cannot authorize acceptance while outside that authority. This does not permanently revoke the invitation; restoring authority can restore usability before expiry. A different authorized administrator may revoke it. Decide any alternative issuer-transfer behavior before adding a UI.

Transactions lock the issuer and invitation, recheck state and create exactly one Staff user together with acceptance/audit records. The users.email unique constraint protects races with existing direct account creation. No existing account is overwritten or reactivated. Password strength matches existing account rules, including confirmation for acceptance. Email verification remains null pending a separate team policy decision.

A nullable unique pending_email reserves the normalized email globally, matching the users.email uniqueness boundary. Accepted/revoked invitations release it; expired reservations are released lazily on the next create/rotation. Historical invitations retain their email and state. Concurrent duplicate creation is rejected by the database, not just a preliminary lookup. Transactions have bounded deadlock retries. Unique constraint failures become safe validation errors without SQL bindings.

## Setup and verification

Apply only pending migrations with `php artisan migrate`; do not seed or recreate live schemas. Optional `DMS_INVITATION_EXPIRY_HOURS` defaults to 24. No SMTP or worker configuration is needed for this batch.

Run `php vendor/bin/phpunit --filter EmployeeInvitation --do-not-cache-result` for the SQLite foundation suite (real concurrency checks skip). Run `php vendor/bin/phpunit -c phpunit.mysql.xml --filter EmployeeInvitation --do-not-cache-result` against the disposable dms_test schema for genuine concurrent acceptance and duplicate-creation processes. Tests must never point at a live schema; Tests\TestCase enforces isolation and rejects connection URLs before database-test traits can run.

Later batches must add CSRF-protected/throttled HTTP entry points, safe guest screens, scoped administration, SMTP delivery and protected queue handling. GET requests must never accept invitations. Resending must use token rotation; old queued links remain invalid. Do not expose the service directly as an unrestricted registration endpoint.
