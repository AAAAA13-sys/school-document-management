# Staff invitations — Batches 1–3

This feature extends FR-05 account management and NFR-01 access control. Batch 1 supplies the invitation foundation. Batch 2 adds scoped administration and synchronous SMTP delivery. Batch 3 connects public token-bound registration to the existing acceptance transaction. Existing direct account creation and password recovery remain unchanged; no authentication stack or dependencies were added. Live invitation dispatch remains disabled by default.

## Service contract

Resolve `App\Services\InvitationService` from the container:

- `create(User $actor, array $attributes): IssuedInvitation` accepts email and source only. Email is trimmed/lowercased and source normalized. Privileged fields are prohibited. Staff role and the actor's school/campus are assigned by the server.
- `validateToken(string $token): EmployeeInvitation` is read-only. It rejects malformed, expired, accepted, revoked or unauthorized-issuer invitations, and existing-account conflicts. Lifecycle errors extend ValidationException with a safe reason (invalid, expired, revoked or used); they contain no token/account/issuer details.
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

Apply only approved pending migrations with `php artisan migrate`; do not seed or recreate live schemas. Invitation creation, delivery tracking and 2026_10_30_000003_fix_employee_invitation_expiration_updates are required before deployment. The additive expiry fix uses DATETIME to prevent legacy MariaDB/MySQL automatic TIMESTAMP updates from expiring invitations during delivery writes; existing stored expiration values are preserved. Historical migrations remain unchanged. Optional `DMS_INVITATION_EXPIRY_HOURS` defaults to 24. No additional queue worker is needed.

Run `php vendor/bin/phpunit --filter EmployeeInvitation --do-not-cache-result` for the SQLite foundation suite (real concurrency checks skip). Run `php vendor/bin/phpunit -c phpunit.mysql.xml --filter EmployeeInvitation --do-not-cache-result` against the disposable dms_test schema for genuine concurrent acceptance and duplicate-creation processes. Tests must never point at a live schema; Tests\TestCase enforces isolation and rejects connection URLs before database-test traits can run.

## Batch 2 administration and SMTP

Staff accounts at `/admin/accounts` now includes paginated invitation history, email preview, Send invitation, Resend and Revoke. Existing Create account and account editing remain available. New endpoints are session-authenticated, active-account checked and restricted to account administrators:

- `GET /admin/invitations/preview` (`invitations.preview`): synthetic HTML email only; no real token, recipient record or message dispatch. No-store headers prevent caching.
- `POST /admin/invitations` (`invitations.store`): creates and synchronously sends through InvitationDeliveryService.
- `POST /admin/invitations/{invitation}/resend` (`invitations.resend`): rotates before sending, invalidating the old link even when SMTP fails.
- `POST /admin/invitations/{invitation}/revoke` (`invitations.revoke`): requires confirm_revoke=1; invalidates the invitation and audits revocation.

Creation/resend share a limit of 5 requests/minute per administrator and 20/minute per IP. All writes use CSRF. School/campus/source restrictions and legacy source-less Admin authority follow Batch 1. Lists omit token hashes; cross-scope management IDs are 404. The existing UI's confirmation handler is reused.

Invitation state (Pending, Accepted, Expired, Revoked) is independent of delivery state (Not sent, Sending, Submitted to SMTP, Delivery Failed). Expiration and acceptance remain authoritative regardless of delivery status. Delivery tracking describes the latest token's attempt; audit entries preserve attempt events by ID/source. No provider exception text or registration URL enters audit data.

InvitationDeliveryService reuses InvitationService, sends synchronously through Laravel Mail, and never queues token-bearing payloads. EmployeeInvitationMail supplies inline-styled responsive HTML and a text alternative, an explicit UTC expiration, one-use/privacy warnings and administrator support instructions. Its JSON/debug output excludes the URL and PHP serialization is prohibited.

Only a mailer whose configured transport is `smtp` is allowed. Log, array, failover and other transports are rejected before invitation creation or rotation. This avoids logging links or silently treating fallback logs as delivery. Mail::fake is supported only in the testing environment; tests must still explicitly enable dispatch and select SMTP. The protected preview works while dispatch is disabled.

Use the existing environment configuration, never committed credentials:

```dotenv
DMS_INVITATION_DISPATCH_ENABLED=false
DMS_INVITATION_EXPIRY_HOURS=24
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS=documents@example.test
MAIL_FROM_NAME="PLP Documents"
MAIL_TIMEOUT=15
MAIL_EHLO_DOMAIN=null
```

These values are local-testing placeholders, not production provider settings. For authenticated SMTP, supply the provider's host/port/username/password and authorized sender privately. MAIL_SCHEME commonly uses smtp with STARTTLS or smtps for implicit TLS; choose according to the provider. MAIL_ENCRYPTION is not used. MAIL_URL, if configured, can override individual SMTP fields and must be reviewed privately. Production SMTP should use TLS; do not disable certificate verification. Keep MAIL_TIMEOUT bounded (default 15 seconds) so requests cannot hang indefinitely. A local Mailpit/MailHog endpoint uses smtp and normally blank credentials; it still cannot receive real invitation mail before the Batch 3 gate is satisfied.

A shared cache lock serializes delivery/resend for one invitation for up to 120 seconds; retain the existing database cache on deployments. Sending takes place after invitation persistence, outside database transactions. SMTP acceptance and database tracking cannot be atomic: a crash may leave Sending, or SMTP may accept a message before tracking fails. In that case inspect history and resend deliberately; resend rotates the token. A failed message remains pending and reserves its email until expiration/revocation. Never retry by reusing a token from a log or queue. Revoking while sending can produce an email whose link is already invalid, which is safe but cannot recall the email.

Successful synchronous sending means SMTP submission, not inbox delivery. Bounces, spam filtering and provider delivery receipts are not tracked. Exceptions are discarded without logging/chaining provider details; a fixed smtp_send_failed code and audit event report failure. A framework-cancelled send is also failure. Database tracking failures do not report success.

## Batch 3 acceptance URL contract and activation gate

The implemented contract is:

- `GET /invitations/{token}`, named `invitations.accept`: validate/display only; never consume the token.
- `POST /invitations/{token}`, named `invitations.register`: CSRF-protected, throttled transactional acceptance through InvitationService.

Both routes are public browser routes and do not require an authenticated session. `/register` remains unavailable. Links are built exclusively from the configured APP_URL plus `/invitations/{token}`, never the incoming request host. APP_URL must use HTTPS in deployment; HTTP is allowed only for loopback hosts in local/testing environments. Credentials, query strings and fragments in APP_URL are rejected. Subdirectory APP_URL deployments are supported.

`DMS_INVITATION_DISPATCH_ENABLED` defaults to false and has not been enabled by Batch 3. Dispatch also requires both named routes with the exact URI/method contract, a valid APP_URL and SMTP transport. Now that routes exist, enabling the flag with valid SMTP/URL configuration can send invitations; do not enable it until the deployment checks below are complete. Mail::fake in testing may bypass only route readiness; it cannot bypass the explicit flag, transport selection or URL checks.

After Batch 3: verify GET display, password validation, expired/revoked/used links, tampering, single-use acceptance, login, organization scope and real MySQL concurrency in isolated environments. Then configure the correct HTTPS APP_URL, provider credentials and sender, enable DMS_INVITATION_DISPATCH_ENABLED=true, rebuild cached configuration if used, and verify delivery using a controlled test recipient. Only then start operational invitations. Never enable unrestricted public registration.

Run Batch 2 tests with `php vendor/bin/phpunit --filter AdminInvitationTest --do-not-cache-result`. They use Mail::fake and a network-free test transport for Laravel's real multipart rendering pipeline; they do not prove an external SMTP account or inbox works.

## Batch 3 registration behavior

The registration page displays the database email read-only, the informational source, full name, password and confirmation. It exposes no role/school/campus/source/active inputs. An optional submitted email must equal the normalized invitation email; the stored email always comes from the locked invitation. Privileged field injection is rejected. Password fields are never repopulated, flashed or returned in JSON. The page renders field errors and preserves only an escaped, length-limited name in its HTML response.

Passwords require 12 characters, mixed case, numbers, symbols and confirmation. The existing User hashing cast is retained; the acceptance service explicitly hashes plaintext before assigning it so a submitted precomputed hash cannot bypass strength checks. Invitation acceptance additionally rejects null bytes and passwords over bcrypt's 72-byte limit to prevent silent truncation (other existing account/password workflows are unchanged).

GET never consumes an invitation, including email-scanner visits. GET is limited to 60 requests/minute per IP. POST is limited to 5/minute per token (hashed limiter key) and 20/minute per IP, and requires CSRF. Acceptance reuses the Batch 1 row-locking transaction, global unique email constraint, trusted organization fields and sanitized audit pipeline. No migration or new identity table was added for Batch 3.

Successful acceptance redirects to the existing `/login` page with a generic success message; it never automatically authenticates. The account is active and email_verified_at remains null, preserving Batch 1's behavior. Existing login initializes auth_version and sends Staff to User::homePath(). An already signed-in browser can inspect the page but must use a signed-out session to submit registration; its existing account/session is not changed.

Invalid/malformed/unauthorized-issuer tokens return 404; expired/revoked/used tokens return 410. Existing account conflicts return a generic 409 without account details. Password/input errors return 422 HTML on the same POST without redirecting or flashing sensitive input. CSRF failures return a safe 419 page, rate limits return 429, and storage/unexpected failures return a generic 503. No token details or administrator information appear on unavailable pages. Repeated or competing submissions cannot overwrite/reactivate an existing account.

## Token page privacy and deployment checks

Token pages use only local styles/scripts, ignoring external asset URL overrides. They include no analytics or third-party resources. Responses, including redirects/errors, use no-store cache headers, no-referrer policy, noindex directives, restrictive same-origin CSP and frame denial. Password visibility and duplicate-submit protection use a separate local JavaScript file without telemetry/network calls.

InvitationSession delegates ordinary requests to Laravel's existing session behavior but skips storing token-bearing GET URLs as previous-page history. Sessions and CSRF still work. Registration controller errors are sanitized; the exception handler suppresses reports for token paths and returns safe HTML even with APP_DEBUG=true or JSON request headers. Do not add token request dumps, request-body logging, debug toolbars or profiler collectors.

These application controls do not configure the web server, reverse proxy, WAF, APM, browser history or mail provider. Before live dispatch, redact/disable `/invitations/*` URLs and password bodies in every infrastructure access log and tracing system; query-string-only redaction is insufficient because the token is in the path. Never copy a real invitation URL into tickets or logs. Keep production APP_DEBUG=false and serve only public/ over HTTPS.

Both application database access and isolated dms_test access must be checked independently. Error 1044 indicates schema access denied; do not broaden grants or change live credentials automatically. Verify the configured target with the database owner, obtain the required schema-specific access, and inspect/apply only pending migrations. Do not run migrate:fresh or seeders against application data. A blocked connection does not establish whether historical migrations are applied.

Verification commands:

```powershell
php vendor/bin/phpunit --filter InvitationRegistrationTest --do-not-cache-result
php vendor/bin/phpunit --do-not-cache-result
php vendor/bin/phpunit -c phpunit.mysql.xml --filter EmployeeInvitationConcurrencyTest --do-not-cache-result
node --test tests/frontend/*.test.mjs
```

SQLite tests cannot prove MySQL row-lock behavior. The MySQL suite includes simultaneous service acceptance, duplicate invitation creation, HTTP registration, rotation/acceptance, revocation/acceptance and expired-reservation replacement processes. TestCase and workers verify the actual selected PDO schema, not only configuration, before writes. Genuine concurrency was exercised during Batch 4 on the disposable dms_test schema; real SMTP/inbox delivery remains a separate controlled acceptance check. Do not enable dispatch merely because automated tests passed. See [the final QA report](INVITATION-FINAL-QA.md) and [the application QA incident/recovery plan](INVITATION-QA-RECOVERY.md) for outstanding blockers.
