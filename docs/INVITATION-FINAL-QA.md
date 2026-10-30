# Final invitation integration and QA report — Batch 4

**Subsequent containment update:** the user-approved five-account/85-invitation containment completed after a private verified backup. See [the execution report](INVITATION-QA-CONTAINMENT-RESULT.md). This document preserves the earlier QA assessment and incident timeline; statements below about pending containment are historical. Live deployment remains blocked by the remaining operational checks and the newly observed MariaDB grant error 1194.

## A. Final system architecture

Laravel 12 custom session authentication, one User model, MySQL/MariaDB InnoDB, Blade/Bootstrap/jQuery and local private document storage remain in place. InvitationService owns token/state/acceptance transactions; EmployeeInvitationPolicy reuses UserPolicy; InvitationDeliveryService performs synchronous SMTP submission; the admin controller integrates Accounts; the guest controller renders and accepts token-bound registration. Existing DMS/ERP services and role policies were not rebuilt.

**Readiness: BLOCKED for controlled live invitation testing.** The application QA incident below requires approved containment; production SMTP/HTTPS/privacy, dependency reproducibility and runtime asset/scanner preparation are outstanding.

## B. Complete feature inventory

| Feature                                         | Classification                    | Evidence/limit                                                     |
| ----------------------------------------------- | --------------------------------- | ------------------------------------------------------------------ |
| Admin/Super Admin scoped creation; Staff denial | VERIFIED                          | Backend suites, existing UserPolicy/Source behavior                |
| 256-bit random token; SHA-256 hash storage      | VERIFIED                          | Source and automated credential/state tests                        |
| 24-hour configurable expiry                     | VERIFIED                          | Service tests and fixed MariaDB persistence                        |
| Rotation, revocation, single-use acceptance     | VERIFIED                          | Functional and genuine MySQL race tests                            |
| Global pending-email reservation                | VERIFIED                          | Unique constraint, concurrent create and expired replacement       |
| Backend email/role/organization enforcement     | VERIFIED                          | Tampering, existing-account and scope tests                        |
| SMTP dispatch gate and failure tracking         | VERIFIED                          | Fake and network-free transport tests; actual flag stays false     |
| HTML/text email generation                      | VERIFIED                          | Subject, branding, expiry, URL, recipient and multipart tests      |
| External SMTP submission / inbox delivery       | BLOCKED                           | No configured provider credentials or approved real recipient test |
| Guest registration and existing login/workspace | VERIFIED                          | Full MySQL HTTP workflow using fake mail                           |
| Admin/registration browser visual behavior      | IMPLEMENTED BUT NOT LIVE-VERIFIED | Render/Node tests pass; Chrome/Edge renderer CDP commands time out |
| Unrestricted public registration                | NOT APPLICABLE                    | /register intentionally remains 404                                |
| Automatic login / global email verification     | NOT APPLICABLE                    | Existing explicit behavior preserved                               |

## C. Changes and bug fixes applied

1. **Confirmed MariaDB expiry defect fixed:** with explicit_defaults_for_timestamp disabled, the original first non-null TIMESTAMP implicitly gained ON UPDATE CURRENT_TIMESTAMP. Delivery updates immediately expired invitations and invalidated resend behavior. Added 2026_10_30_000003_fix_employee_invitation_expiration_updates to use non-null DATETIME, preserving stored values. Historical migrations are unchanged. Regression checks pass on MariaDB and SQLite. Previously affected expirations cannot be inferred/repaired automatically; authorized resend is required where applicable.
2. Added admin invitation busy/progress state and repeat-submit prevention after confirmation, with restore behavior on browser page restoration. The new interaction test checks cancellation and duplicate processing.
3. Subprocess tests use the accessible tests/support working directory. This fixes the execution sandbox's inability to stat the workspace root; production code/workspaces are unaffected.
4. Added genuine rotation/acceptance, revocation/acceptance and expired-reservation races, and a complete Admin → fake email → guest registration → login/workspace test.
5. Hardened TestCase and MySQL subprocess workers to verify SELECT DATABASE() is dms_test before test writes, not just trust a configuration string.

### Application QA incident — FAILED isolation control, containment pending

A temporary browser harness changed database configuration after bootstrap had already resolved an application connection. Its isolation checks were insufficient. It inadvertently applied four pending application migrations (the classification-default migration plus three invitation migrations) and inserted five QA Admin accounts and 85 invitations. The harness was removed and was not retained as application/test functionality.

The five accounts are IDs 11–15. They have no observed sessions, document/folder ownership, share/comment/star dependencies or accepted invitations; 90 fixture audit records must be preserved. They remain active because the user instructed that no cleanup occur before a verified record set and explicit approval. **No cleanup, revocation, deactivation, schema rollback, grant change, .env change or dispatch enablement has been performed.**

Exact evidence and every invitation UUID: [record list](INVITATION-QA-RECORDS.md), [machine-readable manifest](INVITATION-QA-MANIFEST.json), [recoverable containment plan](INVITATION-QA-RECOVERY.md). The additional classification default change/null-backfill impact requires database-owner review against a private pre-incident backup. It is not possible to certify the historical null-row impact from current state alone.

## D. Database and migration verification

- Server reachability and application schema access are now VERIFIED; server is MariaDB 10.4.32. The earlier 1044 denial no longer reproduces. The effective account matches the configured username; no username/credential value is disclosed here.
- dms_test initially did not exist (1049). It was created as the repository's documented disposable schema, separate from the application schema. Migration, constraints, rollback/restore and genuine concurrency tests ran there.
- CLI configuration is not cached and has no database URL override. PHPUnit forces testing/dms_test or SQLite :memory:, array sessions/cache and fake/non-network mail; the selected physical schema is now checked before writes.
- Both original invitation migrations have valid ordering after users: UUID primary ID, globally unique token hash and nullable unique pending_email, scope/email/expiry indexes, and restrictive issuer/accepted-user FKs to the existing unsigned users.id. Delivery state is independent of acceptance state. The additive DATETIME correction is required on legacy TIMESTAMP configurations.
- All three invitation migrations are now applied in the application schema **because of the incident**, not an authorized deployment. No further application migration action is approved.
- Existing grants include broad scope; production should use a dedicated least-privilege application identity and separate migration/test identities. No grants were altered.
- The old Batch 1 temporary MariaDB directory is not the current server datadir. Its contents are initialized engine/system/test files, not document storage. It remains untouched; removal still requires approval and operator confirmation.

## E. SMTP and email delivery verification

Current configuration classifications: MAIL_MAILER=log; SMTP host is local; username/password are unpopulated; MAIL_SCHEME unset; MAIL_URL absent; timeout 15 seconds; sender fields syntactically present; APP_URL uses local loopback HTTP. Actual addresses, host values and credentials are intentionally omitted. DMS_INVITATION_DISPATCH_ENABLED remains false.

Standard authenticated SMTP is implemented through existing config/mail.php, with MAIL_SCHEME and environment credentials. MAIL_URL takes precedence where configured and must be reviewed privately. Log/array/failover are rejected for invitation delivery. There are no token-bearing queued jobs. The synthetic preview sends nothing.

1. **Email generated: VERIFIED.** Branded inline-styled HTML and plain text, prominent button, fallback URL, recipient, explicit UTC expiry, one-use warning and support guidance were tested. Rendering compatibility in real Outlook/Gmail/mobile clients is not live-verified.
2. **SMTP accepted submission: IMPLEMENTED BUT NOT LIVE-VERIFIED.** The actual Laravel pipeline was exercised with a network-free transport; this is not proof of a provider handshake, TLS/authentication or external submission.
3. **Recipient inbox delivery: BLOCKED.** Requires authorized controlled recipient/provider access and inbox observation. SMTP acknowledgement alone would not prove this.

Provider exceptions are discarded without logging/chaining sensitive text. Fixed failure codes, audit events and admin feedback reflect failure. Resend rotates before sending even when sending fails. SMTP and database tracking cannot commit atomically; uncertain attempts require operator review and deliberate rotation/resend.

## F. Invitation and registration security audit

VERIFIED controls include non-consuming GET, POST CSRF, token/IP throttles, token hashing/entropy, lifetime/revocation/acceptance checks, current issuer authority, trusted Staff role/school/campus/source, readonly email backed by server validation, duplicate-email constraint, transactional user/acceptance/audit writes, rollback on audit failure, and no auto-login. Precomputed hash input cannot bypass password validation; bcrypt's 72-byte limit/null bytes are handled. Existing accounts/password-change policy remains untouched.

MySQL races are linearized by row locks: if revocation commits first, acceptance fails; if acceptance commits first, revocation fails. Neither state may be both accepted and revoked. Rotation makes the prior token invalid. The six concurrent tests cover the identified cases; the suite does not establish arbitrary high-load capacity or every possible database fault schedule.

Token pages use local assets, no-referrer/no-store/noindex/CSP/frame protections, safe HTML errors and no password flashing. Session history excludes bearer URLs. App exception reporting for token paths is suppressed/sanitized. One existing application log was scanned for raw /invitations/<64-hex-token> patterns and found zero matches; this is a limited scan, not proof that all historical secrets or external logs are clean.

The inspected local Apache configuration logs complete request lines and referrers and has no invitation redaction. Its DocumentRoot is the generic XAMPP htdocs, not a verified DMS production virtual host. External proxies, WAF/CDN/APM and production TLS configuration were not supplied/verified. Configure path-level redaction and disable body capture before dispatch.

Direct administrative account creation remains an authorized, tested exception requested in Batches 1–3. The deployment cannot be described as strictly invitation-only across every provisioning path until the team explicitly approves changing that exception. Legacy source-less Admin authority is preserved. email_verified_at remains null; no verified middleware/MustVerifyEmail requirement exists, so current login/workspaces are unaffected. This does not introduce a persisted email-verification attestation.

## G. End-to-end workflow testing

InvitationWorkflowTest passes on real MariaDB: Admin opens Accounts → submits permitted recipient/source → invitation stored/expiry maintained → fake Mailable captured/rendered → Admin logs out → guest follows real route → readonly email shown → password/name submitted → Staff created with correct organization → invitation consumed/reservation released → redirect to login → login succeeds → assigned ES workspace opens and EMS/Admin access is denied. This verifies all application stages, replacing external delivery/inbox stages with an explicitly fake capture.

## H. UI/UX review and fixes

Blade rendering/Node interaction checks verify labels, readonly email, password visibility, feedback, registration duplicate submission, admin confirmation/busy states, pagination/status/empty states, success redirect and error states. CSS uses responsive registration width and horizontally scrollable invitation tables. No framework redesign was introduced.

Chrome and Edge launched, but renderer debugging commands (Page.enable / Runtime.runIfWaitingForDebugger) timed out in this execution environment. No completed browser interaction or screenshot acceptance is claimed. Run the manual desktop/mobile checklist below on a properly isolated staging instance. Temporary browser profiles in storage/framework/testing/invitation-browser-qa-* contain synthetic test artifacts; leave them for operator-approved cleanup.

### Manual browser acceptance (not executed successfully here)

1. Use staging with a dedicated test-only database user/schema; verify actual SELECT DATABASE() before fixtures. Never use the application schema. Keep live dispatch false and obtain synthetic fixture links through an approved private test mechanism.
2. At 1440px, 390px and 320px, inspect Accounts and registration for page overflow, internal table scrolling, spacing, typography, focus visibility and button hit areas.
3. As scoped Admin and Super Admin, verify permitted source choices, history pagination, empty states and all lifecycle/delivery badges. Confirm Staff cannot enter Accounts.
4. Cancel a revoke/resend confirmation: no action/busy lock. Confirm: one submission, visible progress, no duplicate request. Check disabled dispatch controls and synthetic preview.
5. Open a valid registration link twice: database state remains pending. Check email readonly, informational source, labels, keyboard navigation and password toggles.
6. Submit mismatched/weak passwords: errors visible/focused, name preserved/escaped, passwords empty. Tamper with email/role/source/scope: no account created.
7. Submit correctly: success on existing login, no auto-login. Login and verify assigned workspace, cross-source denial and Admin denial. Reopen/resubmit old link: used-state page, no duplicate account.
8. Exercise expired, revoked, rotated, invalid, throttled, CSRF-expired and unavailable states. No token/password/error trace should appear in those state pages, JSON, analytics or logs.
9. After separate authorization for a synthetic recipient/local sink, test sending/resending/failure recovery; observe receipt and click the message link. Inspect headers/network for no external token-page requests and upstream log redaction. Do not enable real-employee dispatch during QA.

## I. Existing DMS regression results

Final completed suites before the last documentation-only changes:

| Suite/check                        | Passed | Failed | Skipped | Assertions/notes                                                |
| ---------------------------------- | -----: | -----: | ------: | --------------------------------------------------------------- |
| SQLite full suite                  |    157 |      0 |      10 | 167 tests, 6,875 assertions                                     |
| MariaDB full suite                 |    166 |      0 |       1 | 167 tests, 6,958 assertions; six invitation race tests executed |
| Node frontend suite                |     25 |      0 |       0 | Node reports test counts, not aggregate assertions              |
| Formatting / encoding / whitespace | Passed |      0 |       — | Scoped Pint, UTF-8 and git diff checks                          |
| Composer manifest validation       | Passed |      0 |       — | Run from accessible tests directory with ../composer.json       |
| Frontend asset integrity check     |      — |      1 |       — | FAILED: missing generated public/vendor/pdfjs/pdf.mjs           |
| Real ClamAV opt-in test            |      — |      — |       1 | BLOCKED: no configured engine/signature directory               |
| Browser visual automation          |      — |      — |       — | BLOCKED: renderer CDP timeouts; no completed browser result     |

MySQL coverage includes existing ERP history/upload contention, native dump/restore, auth/account changes, role/source/campus isolation, document versions/checksums/previews/scans (mocked), sharing, folders, classifications and audits. Scanner mock success is not proof of a functioning local engine.

## J. Dependency and configuration review

CLI PHP 8.2.12 and Node 24.22.0 are available. Installed Laravel is 12.69.3, lockfile Laravel is 12.70.0; 13 installed package versions differ from the lock, including framework/prompts and Symfony components. Current suites pass against the installed set, establishing no observed incompatibility there, not compatibility/security of an untested locked deployment.

composer.lock is the reproducible installation target paired with composer.json. Synchronize in isolated staging using composer install (not update), then rerun suites before deployment. No versions/packages were changed during this audit. The repository also lacks installed frontend dependencies/generated PDF.js assets; npm ci followed by npm run build is required on the build host.

Local APP_DEBUG is false, config is uncached, cache is database, sessions are database/HTTP-only/SameSite lax. SESSION_ENCRYPT is false and secure-cookie is unset; use appropriate encrypted/secure HTTPS session settings in production. Web-runtime environment parity and filesystem/process permissions remain deployment checks.

## K. Remaining issues and risks

| Item                                     | Classification                    | Exact blocker/action                                                                                    |
| ---------------------------------------- | --------------------------------- | ------------------------------------------------------------------------------------------------------- |
| QA account/invitation containment        | BLOCKED                           | Exact five/85 manifest verified; fresh approval needed before deactivation/revocation                   |
| Classification migration incident impact | BLOCKED                           | Compare private pre-incident backup for historical null classification impact; no automatic rollback    |
| External SMTP/inbox                      | BLOCKED                           | Configure provider/sender privately; authorize controlled recipient; verify both submission and receipt |
| HTTPS and infrastructure token privacy   | BLOCKED                           | Confirm real virtual host/TLS/proxies and redact path/body logs across all layers                       |
| Browser desktop/mobile acceptance        | BLOCKED                           | Use functional browser environment and isolated staging; execute manual checklist                       |
| PDF.js deployment assets                 | FAILED                            | Install lockfile frontend dependencies and build/copy generated assets                                  |
| Real malware scanner                     | BLOCKED                           | Install/configure local ClamAV plus current official signatures and rerun opt-in check                  |
| Dependency parity                        | IMPLEMENTED BUT NOT LIVE-VERIFIED | Reinstall from lock in isolated staging and rerun tests                                                 |
| Strict invitation-only policy            | IMPLEMENTED BUT NOT LIVE-VERIFIED | Team must explicitly retain/revise authorized direct admin creation exception                           |

## L. Deployment and rollback checklist

- Resolve approved QA containment first; preserve records, migrations and audit history. No cleanup is authorized yet.
- Review classification schema impact against a private backup. Preserve DB/document/APP_KEY consistency; do not regenerate APP_KEY.
- Use supported PHP/extensions, locked Composer dependencies and built frontend assets; run all suites in the disposable schema.
- Review effective application/migration/test database identities and schema-specific grants. Keep test credentials unable to access the application database.
- Review migration list, backup, maintenance window and approval before applying anything. This application already has the four incident migrations applied; do not reapply/rollback blindly.
- Serve only public/ over HTTPS; verify APP_URL, proxy trust, cookie security, session encryption and filesystem permissions.
- Verify local ClamAV and fresh signatures. Existing scan worker/scheduler remain required; no additional email queue worker is introduced.
- Configure authenticated SMTP/sender with environment secrets, MAIL_SCHEME/MAIL_TIMEOUT and private MAIL_URL review. Never use log fallback for invitation delivery.
- Verify log/path/body redaction and disable production debug/profilers on token routes. Build/cache config only after approved environment configuration; restart existing workers after config/code changes.
- Run the manual workflow with controlled synthetic identities and an explicitly authorized recipient/sink. Verify provider submission and actual inbox receipt separately.
- Only the authorized deployment owner may enable DMS_INVITATION_DISPATCH_ENABLED after all these checks and incident containment are complete. It stays false now.
- Recovery: disable dispatch first; stop new invitations; keep existing records/audits. Roll back code from a known artifact with additive schema retained. Resend/rotate uncertain deliveries through audited controls after repair. Never restore old tokens, decrement auth_version blindly, rollback expiry protection or drop tables to undo this feature. Use a coordinated private restore only with separate approval.

## M. Exact setup and verification commands

Read-only/isolated verification:

```powershell
php artisan migrate:status
php artisan route:list --path=invitations --except-vendor
php vendor/bin/phpunit --do-not-cache-result --no-progress
php vendor/bin/phpunit -c phpunit.mysql.xml --do-not-cache-result --no-progress
node --test tests/frontend/*.test.mjs
node scripts/check-frontend.mjs
php scripts/check-encoding.php
git diff --check
```

The MySQL command resets only the explicitly disposable dms_test schema through test traits; never repoint it to application/shared data. Its active PDO schema guard must pass before any test write.

On an authorized build/staging host, not executed here:

```powershell
composer install --prefer-dist --optimize-autoloader
npm ci
npm run build
```

Only after migration/environment review and explicit deployment authorization:

```powershell
php artisan migrate --force
php artisan config:cache
php artisan queue:restart
```

Do not run that block merely to resolve the incident; current application migrations are already applied. The current five-account/85-invitation containment requires the distinct approval/procedure in INVITATION-QA-RECOVERY.md.

## N. Final production readiness assessment

Application invitation logic, complete HTTP workflow and genuine MariaDB concurrency are VERIFIED on the installed dependency set. Controlled live testing is **not ready**: first obtain and execute approved QA containment, review schema impact, prepare dependencies/assets/scanner and production HTTPS/privacy, complete browser acceptance and authorize controlled external mail verification. No push, merge, production deployment, grant change or live dispatch enablement was performed. The application incident is explicitly recorded rather than treated as an approved deployment.
