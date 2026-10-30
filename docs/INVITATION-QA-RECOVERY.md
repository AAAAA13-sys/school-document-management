# QA incident: verified scope and proposed recovery

Status: **APPROVED, EXECUTED AND VERIFIED** at 2026-10-30 15:42:36 UTC, after private backup/restore verification and locked record revalidation. See [the execution report](INVITATION-QA-CONTAINMENT-RESULT.md). The sections below preserve the original review and approved plan; the immutable manifest remains the pre-containment snapshot.

## What happened

The temporary browser harness bootstrapped Laravel against the configured application connection, then changed its database configuration to dms_test. A connection already resolved during bootstrap retained the application database. Configuration checks alone did not establish actual connection isolation. The harness inadvertently ran pending migrations and created synthetic fixtures in the application schema. No live invitation dispatch was enabled.

Read-only migration-history inspection identifies four entries in that application migration batch: 2026_10_23_000002_default_document_classification_to_internal and the three 2026_10_30 invitation migrations (creation, delivery tracking, expiry fix). The classification migration changes the default to Internal, preserves existing explicit classifications, and includes a null-to-Internal backfill. The pre-change null-row count cannot be reconstructed from current records without a pre-incident backup. It would be incorrect to assert that no legitimate document data could have changed. No schema/classification rollback is proposed or authorized; the database owner must review this additional impact against a private backup.

The harness code was removed. TestCase and subprocess workers now verify SELECT DATABASE() is exactly dms_test before MySQL test writes; a mismatch or inability to verify fails closed. No future browser-writing harness should be introduced without this effective-connection check, test environment variables set before bootstrap, and a separate database account restricted to the disposable test schema.

## Verified target records

- Account IDs: **11, 12, 13, 14, 15**.
- Exact emails, timestamps, and all **85 invitation UUIDs**: [verified record list](INVITATION-QA-RECORDS.md).
- Original mutable values and dependency counts: [read-only manifest](INVITATION-QA-MANIFEST.json).
- All five accounts: name Browser QA Administrator; role admin; source EMS; school BROWSER-QA; campus Isolated; active=1; auth_version=1.
- Each administrator has one unique ten-character hex suffix in its qa-admin-<suffix>@example.test email. Its 17 invitations have exactly qa-staff-<same suffix>-0 through -16@example.test addresses.
- Creation timestamps match the five temporary browser harness runs. Each actor has exactly 17 invitation.created and one invitation.revoked audit entry, matching the fixture implementation.
- All 85 invitations have role staff, source EMS, school BROWSER-QA, campus Isolated, no accepted_at/user, and delivery_status=not_sent. Five were already revoked as test fixtures. Five unrevoked rows were deliberately expired.
- No additional users or invitations were found in that synthetic school scope outside this exact set.

These multiple matching identifiers establish the selected records as harness-generated QA fixtures. The proposed operation must use the exact IDs from the manifest and revalidate every identity predicate under locks; a broad email wildcard or school-wide update is not acceptable.

## Dependencies checked

For **each** account 11–15:

| Dependency                                                 | Count |
| ---------------------------------------------------------- | ----: |
| Configured database sessions, including unexpired sessions |     0 |
| Owned documents / owned folders                            | 0 / 0 |
| Document share / folder share recipients                   | 0 / 0 |
| Comments / stars                                           | 0 / 0 |
| Invitations accepted as a user                             |     0 |
| Invitations issued                                         |    17 |
| Audit entries as actor                                     |    18 |

The 90 audit rows are exclusively 85 invitation.created and five invitation.revoked events. No sign-in activity was found for these actors. All foreign-key references to users/invitations in information_schema were reviewed, plus sessions.user_id (which is indexed but not constrained). The configured application session store is the database. Unknown external deployments/session stores have not been inspected.

No legitimate account or invitation is included in the manifest. No document/folder ownership or recipient dependency requires reassignment. This is a snapshot, not a guarantee against subsequent changes: recheck immediately before any approved operation and abort if counts, identity fields, acceptance, dependencies or updated_at values changed.

## Proposed containment — only after fresh explicit approval

1. Keep dispatch disabled. Confirm the application's effective SELECT DATABASE() fingerprint matches the manifest. A database owner should preserve a private, consistent backup according to OPERATIONS.md; never expose password hashes or token hashes in chat or this document.
2. In one transaction, lock users 11–15 and the exact 85 invitation IDs listed in the manifest. Compare name, email, role, source, school/campus, timestamps and original state to the manifest. Confirm exactly five accounts, 85 invitations, no accepted invitations and no new dependencies. Abort on any discrepancy.
3. Set active=false and increment auth_version for **only** those five matched accounts. Do not change their roles, school/campus/source, names, email, password hashes or remember tokens. ActiveAccount will reject old sessions and login requires active=true. No session deletion is needed for the currently verified zero-session state.
4. Revoke the **80 currently unrevoked** manifest invitations and clear pending_email for those exact records. Preserve the five existing revocation timestamps. Do not modify acceptance, expiration, token hashes, delivery state, or any other invitation. No messages are sent.
5. Add sanitized recovery audit entries with the real executing administrator/operator identity, exact target IDs, count and reason. For an authorized server operator without an application identity, actor_id may be null; never attribute recovery to an unrelated employee. Preserve all 90 existing audit entries.
6. Before commit, verify five inactive target accounts, all 85 target invitations revoked, zero target pending-email reservations, zero accepted targets, and the same document/folder/share dependencies. Verify no rows outside the manifest changed. Roll back on failure.
7. Commit and read back the same assertions. Check blocked login and unavailable invitation behavior using isolated replicas/tests, without exposing real bearer URLs or changing legitimate users.

This procedure does **not** delete rows, drop tables, reverse migrations, reset application passwords, touch legitimate accounts or enable dispatch. It was executed only after the subsequent explicit approval and mandatory backup verification; exact results are in the execution report.

## Recoverability

Before commit, transaction rollback restores all changes. The manifest records original active/auth_version/update fields and invitation revocation/reservation values without credential material. After commit, any reversal requires a separate approval and current-state review: do not blindly restore old auth_version values or pending-email reservations, which could revive sessions or conflict with newer invitations. Preserve the recovery audit entries. Re-enable an account only through the normal authorized administrative process if the record is independently shown to be legitimate; these five are verified QA fixtures.

Leave all four applied migration entries and existing tables in place. A schema or classification rollback is a distinct, higher-risk operation requiring separate authorization and a private backup; it is not part of the recommended containment.

## Current exposure

Containment is complete: all five accounts are inactive with auth_version=2, all 85 invitations are revoked, and zero target reservations/sessions remain. Dispatch stays disabled. The remaining deployment and restricted-QA-identity blockers are listed in the execution report; no further cleanup or schema rollback is implied by this approval.

## Future QA isolation requirements

- Use a fresh process with APP_ENV=testing and DB_DATABASE=dms_test set before Laravel bootstraps.
- Use a dedicated test-only database identity that cannot access the application schema.
- Reject cached configuration/connection URL overrides that select another schema.
- Verify the actual PDO connection with SELECT DATABASE() immediately before any migration, fixture creation or browser request that can write; do not trust configuration strings alone.
- Require tests/support/require-isolated-mysql.php for MySQL worker/QA entry points and retain TestCase's effective-schema guard.
- Keep real dispatch disabled; use Mail::fake or an explicitly isolated sink with synthetic recipients only.
- Browser automation remains blocked in this environment; no application-writing browser tests may resume.
