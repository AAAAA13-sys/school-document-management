# Approved QA containment — execution and verification report

Status: **COMMITTED AND POST-COMMIT VERIFIED** at **2026-10-30 15:42:36 UTC**.

This operation used the user's explicit approval and was limited to the five verified synthetic application accounts, their approved invitation UUIDs, and sanitized recovery audit entries. No cleanup was performed before backup verification. Original evidence remains in [the immutable pre-containment manifest](INVITATION-QA-MANIFEST.json) and [the exact record list](INVITATION-QA-RECORDS.md). The manifest's old NOT APPROVED label records its original snapshot, not the later authorization.

## 1. Private recoverable backup

- Private directory: `storage/app/private/recovery/qa-containment-20261030T153721Z/`.
- Full application database dump: `application-before-containment.sql`, **76,961 bytes**.
- SHA-256: `6a8e8ef0eb5cf5a2e0173f14e80ea678885a4f47ee4801b89bb9cabb5316ed1b`.
- Native dump and restore both exited successfully.
- Restored into a new separate verification schema, `dms_recovery_verify_20261030_153721`. No existing schema was overwritten or reset.
- All **23 application tables** matched the restored copy by ordered row-content fingerprint and column-definition fingerprint. The backup checksum was rechecked before containment. No cross-schema foreign keys or stored events/routines/triggers were found that could redirect verification writes into the application.
- The directory has inheritance disabled and access granted only to the current execution identity, SYSTEM and Administrators. ACLs on the SQL dump, checksum, verification proof, original manifest copy and containment result were rechecked. The directory is outside public/ and excluded from Git.
- The private SQL dump, proof and verification schema are retained. This is a database recovery point; it does not copy document file bytes, which the containment did not modify. No password, token hash, raw token or database credential is reproduced in this report.

## 2. Locked revalidation and abort conditions

The active PDO application-schema fingerprint matched the approved manifest and verified backup. The manifest SHA-256 was required to equal `b2c36549cbd5e5d6f6fe14aac784a8f9c293729ad642836d521c44fec1367ffe`.

In one transaction, the operation locked accounts 11–15, the exact 85 invitation IDs, issuer ownership sets, applicable session/dependency ranges and existing QA audit rows. Full target rows matched the verified restored backup, including attributes not exposed in the public manifest. The original identity, scope, role, timestamps and state matched the manifest. All other invitations issued by these accounts were checked to ensure no unlisted ownership existed.

The operation would abort on any mismatch, new ownership/share/comment/star dependency, accepted invitation, new session, unreviewed FK reference, changed audit history, unexpected count or unrelated-record difference. All checks passed. No drift exception was waived.

## 3. Exact affected accounts

| Account ID | Synthetic email                  | Active before → after | Auth version before → after |
| ---------- | -------------------------------- | --------------------- | --------------------------- |
| 11         | qa-admin-3fc7cf845c@example.test | 1 → 0                 | 1 → 2                       |
| 12         | qa-admin-8abfe716bf@example.test | 1 → 0                 | 1 → 2                       |
| 13         | qa-admin-029acb3fe1@example.test | 1 → 0                 | 1 → 2                       |
| 14         | qa-admin-16f1c5bc32@example.test | 1 → 0                 | 1 → 2                       |
| 15         | qa-admin-c79771b8a3@example.test | 1 → 0                 | 1 → 2                       |

Only active, auth_version and updated_at changed. Passwords, remember tokens, roles, identity, school/campus/source and all other account fields were preserved. Existing login requires active=true; ActiveAccount rejects inactive users and obsolete auth versions.

**Sessions:** zero target sessions existed under locks, and zero remained after verification. No session records were deleted. The version increments additionally invalidate previously issued authentication versions, while inactive status blocks access even if an old session lacked a version field.

## 4. Exact invitation effects

- **80** previously unrevoked manifest invitations received revoked_at, had pending_email cleared, and received updated_at.
- **5** already revoked manifest invitations were preserved byte-for-byte, including their original revocation and update timestamps.
- **85/85** target invitations are now revoked.
- **0** target pending-email reservations remain.
- **0** target invitations are accepted; none became associated with a new user.
- Expiration, token hashes, recipient identity, organization, source, intended role, delivery status and every other field were preserved.

The machine-readable [execution result](INVITATION-QA-CONTAINMENT-RESULT.json) lists the exact 80 newly revoked UUIDs, five preserved UUIDs, all 85 target UUIDs and new audit IDs. The original five-account/85-invitation inventory remains available for comparison.

## 5. Audit and unrelated-record preservation

- All **90 original QA audit entries** remain unchanged.
- **85 sanitized audit entries** were added: five account.qa_deactivated and 80 invitation.revoked events.
- New audit IDs are **103–187**, with the exact list retained in the execution result.
- Events identify authorized server-operator containment, target IDs, manifest checksum and reason. actor_id/connector_id are null rather than falsely attributing the operation to a legitimate employee. No credentials or bearer material were recorded.
- Ordered row fingerprints for every unrelated application record matched before and after the transaction. Separate per-row checks confirmed that target fields outside the approved mutations and the five pre-existing revocations were unchanged.
- **No application record deletion, table change, migration change, schema rollback, document change, classification change or configuration change occurred during containment.** No legitimate account or invitation was targeted.

## 6. Document-classification investigation — read-only

The accidentally applied migration was `2026_10_23_000002_default_document_classification_to_internal.php`.

Its schema operation changes the default from Confidential to Internal. Its data operation updates only rows where classification IS NULL. It does not intentionally rewrite an existing explicit Internal, Confidential or Restricted classification, and it does not record per-row audit events or increment document revision/update metadata for that backfill.

Findings:

- The original DMS migration defined classification as non-null VARCHAR(20), default Confidential. No reviewed prior migration makes it nullable. An undrifted installation should therefore have no eligible null rows.
- The current column is non-null VARCHAR(20), default Internal.
- The application currently has **zero document rows and zero document-version rows**. The verified pre-containment backup also has no document rows. There are no currently stored classification rows to restore.
- There are **zero pre-incident document.updated audit rows carrying classification-after history**.
- Binary logging is OFF, general logging is OFF and performance_schema is OFF. No pre-incident SQL backup was found in the inspected workspace storage/docs paths. External DBA backups were not supplied.

Conclusion: there are no currently identifiable affected document IDs. Existing explicit values were outside the backfill predicate. However, the available evidence cannot conclusively reconstruct whether a historically drifted nullable row existed at migration time. A pre-incident backup or separately retained row-level history would be needed for that historical conclusion. The schema default remains changed; no classification restoration or migration rollback was authorized or performed.

## 7. Reconciliation: two original invitation migrations plus one correction

| Exact filename                                                     | Purpose                                                                           |
| ------------------------------------------------------------------ | --------------------------------------------------------------------------------- |
| `2026_10_30_000001_create_employee_invitations_table.php`          | Batch 1 invitation table, token hash, scope, state, reservation, keys and indexes |
| `2026_10_30_000002_add_employee_invitation_delivery_tracking.php`  | Batch 2 delivery status, attempt/sent times and safe error code                   |
| `2026_10_30_000003_fix_employee_invitation_expiration_updates.php` | Batch 4 additive correction: changes expires_at to DATETIME                       |

The third migration was added after genuine MariaDB tests demonstrated that the first non-null TIMESTAMP implicitly acquired ON UPDATE CURRENT_TIMESTAMP with explicit_defaults_for_timestamp disabled. Delivery writes could immediately expire invitations. The correction preserves stored expiry values and does not edit historical migrations. These three invitation migrations are distinct from the earlier document-classification migration. All four applied migration entries and all tables were preserved during containment.

## 8. Future QA identity — provisioning blocked, fail-closed

A new dedicated test-only database identity was created **locked**. No existing database identity, application credentials or configuration was altered. Its name is recorded in the private operational script for the database owner; no password is disclosed.

Granting privileges restricted to the exact disposable dms_test schema failed with **MariaDB driver error 1194**, the marked-crashed-table error class. The test identity remains **locked**, has **zero schema grants**, no application privileges, and cannot be used to run future browser QA. No REPAIR, privilege-table workaround, broad grant or automatic unlock was attempted after the failure. The exact damaged privilege table requires database-owner diagnosis; the failed operation does not establish that application InnoDB data is damaged.

The database owner must preserve system-table recovery material, diagnose/repair the privilege-table problem under separate authorization, then provision an identity with only disposable-schema privileges. Escape the underscore in database-level privilege patterns so similarly named schemas do not match. Verify no application/global DML privileges and no GRANT OPTION before unlocking. Store test credentials privately; do not copy them into the application .env.

The unsafe browser harness is removed. TestCase and worker entry points verify the actual SELECT DATABASE() result before test writes. Future browser QA is prohibited until it uses the restricted identity, test settings are supplied before bootstrap, and both effective identity and actual selected schema are checked. Existing broad application credentials must not be reused for browser QA.

## 9. Skipped operations and retained recovery material

- No session deletion: no target sessions existed; inactive status and auth-version increments provide invalidation.
- No changes to the five existing revocations: explicitly preserved.
- No document classification updates or migration rollback: not approved and no currently stored affected rows exist.
- No database repair or usable QA credential issuance: blocked by error 1194.
- No real SMTP dispatch, environment edit, merge, push or deployment.
- No unrelated database reset/deletion or removal of the old temporary MariaDB directory.
- The verified SQL backup and standalone restore schema remain retained for recovery, as do private execution proofs. Any future removal is a separate retention/approval decision.

Containment verification used the real application transaction and exact read-back checks, not a fabricated test result. Broad PHPUnit/browser suites were not rerun against the application; previous isolated QA suite results remain documented in INVITATION-FINAL-QA.md.

An additional independent read-only transaction compared every unrelated row in all 23 application tables against the restored pre-containment backup: all matched. It also reconfirmed five inactive accounts with auth_version=2, 85 revoked invitations, zero pending reservations, zero accepted target invitations, zero target sessions, 90 original QA audits, 85 added audits and byte-identical preservation of the five prior revocations. Backup and manifest checksums still matched, and dispatch remained disabled. `git diff --check` and `php scripts/check-encoding.php` also passed.

### Recovery procedure if separately authorized

1. Retain the private SQL, checksum, proof and standalone verification schema. Before recovery, independently recheck the SQL checksum and restore to a newly named, non-web-connected schema if a fresh recovery copy is needed. Never import this full dump over the application database.
2. Compare current target rows with the recorded post-containment state and the pre-containment copy. Stop on drift or additional dependencies; do not overwrite later legitimate changes.
3. Obtain separate approval for the exact recovery fields and records. The current safe default is to keep these synthetic accounts inactive and their invitations revoked. The pre-containment copy contains usable fixture account state and must never become the application's runtime database.
4. If a correction is approved, use one transaction with row locks and exact IDs from the immutable manifest. Copy only individually approved attributes from the recovery copy, validate affected counts, preserve every existing audit entry and add a sanitized compensating audit event. Restoring an old auth_version or pending-email reservation blindly is unsafe.
5. Recheck all unrelated-row fingerprints and authentication/invitation invariants before commit, then verify again after commit. No recovery mutation has been performed.

## 10. Remaining deployment blockers

1. Database owner must resolve error 1194 and establish the usable restricted QA identity before any future browser-writing QA.
2. Actual SMTP provider authentication, authorized sender, server submission and inbox receipt remain unverified; dispatch stays disabled.
3. Production HTTPS, proxy trust, secure/encrypted session settings and token/password log redaction need deployment verification.
4. Desktop/mobile browser acceptance remains blocked by renderer debugging timeouts in this execution environment.
5. Generated PDF.js assets are missing; prepare locked frontend dependencies and build artifacts.
6. Local ClamAV engine/signatures are unconfigured here.
7. Installed dependencies differ from composer.lock; synchronize and retest in isolated staging.
8. Historical classification impact cannot be established beyond the current evidence without an external pre-incident backup/history. Preserve the default/schema and review rather than guessing a rollback.

The synthetic-account containment blocker is resolved. These remaining items still prevent a production-ready or controlled-live-testing sign-off.
