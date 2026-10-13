# Implementation plan

Updated 13 October 2026. Planning document based on the repository's current documentation and selected code review; no new application tests, visual checks, migrations or live integrations were executed for this review.

## Purpose and authority

[MASTERPLAN.md](MASTERPLAN.md) owns scope, DMS-01 through DMS-24 and production release gates. [AGENTS.md](AGENTS.md) owns engineering rules. This document turns that backlog into ordered implementation tasks with dependencies and evidence. It does not replace either document or declare planned work complete.

Read [docs/README.md](docs/README.md), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/RBAC.md](docs/RBAC.md), [docs/DRIVE-WORKSPACE.md](docs/DRIVE-WORKSPACE.md), [docs/STUDENT-WORKSPACE.md](docs/STUDENT-WORKSPACE.md), [docs/HISTORY-SYNC.md](docs/HISTORY-SYNC.md), [docs/INTEGRATION.md](docs/INTEGRATION.md) and [docs/WORKFLOW-OWNERSHIP.md](docs/WORKFLOW-OWNERSHIP.md) for current contracts.

The local sdd.pdf has not been read. This plan derives from repository sources; any later SDD review must resolve differences against accepted product decisions before changing scope.

## Baseline and constraints

The repository documents an implemented Laravel DMS with local authentication, scoped accounts, private additive versions, validation/checksum retrieval, folders, recoverable Trash, stars, named pinned-version shares, comments, received snapshots and cursor feeds. Routes, shared authorization/intake logic, history controller and naming migration were inspected to ground this plan; this is not a full code audit.

One application uses PHP 8.2/Laravel 12/Blade, Bootstrap CSS/jQuery/jQuery UI and MySQL/InnoDB. Five systems keep independent databases. DMS preserves delivered evidence; source systems retain business authority. Scanning and DMS business approval are excluded.

MySQL is the target; documented local preview remains SQLite. Historical test counts and configured CI are not new execution evidence. Production readiness, source completeness and design fidelity remain unestablished.

## Documentation findings

| Finding | Action |
|---|---|
| AGENTS.md already existed; MASTERPLAN.md is the active product plan | Replace engineering guidance and add this execution plan; retain product decisions and story IDs |
| DELIVERY-STATUS.md still contains obsolete scanning/review/sharing claims and inconsistent old test counts | Keep it historical; current requirements come from the master plan and feature contracts |
| JQUERY-UI.md lists permanent guide accordion examples and links Laravel 13 documentation while this repository targets Laravel 12 | Verify actual view/assets before updating widget guidance and version references |
| Replay documentation omits an important credential boundary | HistoryController currently looks up event replay by integration_account_id plus event_id; prove/document rotation and replacement behavior before promising uninterrupted replay |
| Source schemas, snapshot/change contracts and policies are missing | Obtain agreed fixtures and policy decisions; do not invent completeness or identity mappings |

## Execution sequence

Work advances by acceptance evidence rather than calendar estimates. UI and correctness work can proceed independently of source contract agreement.

### Step 1 — Establish reproducible evidence and align current docs

Stories: DMS-01; preparation for DMS-15–17.

1. Inspect the checkout, applicable instructions, configured database and existing user changes.
2. Install locked Composer dependencies in an isolated development environment; preserve existing .env and APP_KEY. Provision a dedicated MySQL test database.
3. Run the SQLite feature suite, frontend folder-upload test, Blade compilation and MySQL suite. Record actual environment/version/results rather than copying historical counts.
4. Verify documentation paths, widget usage and API behavior; correct current references while retaining labeled historical material.
5. Record gaps and attach available CI run links.

Exit: reproducible baseline results or explicit execution blockers, consistent current documentation, and no destructive action against existing data.

### Step 2 — Finish the personal workspace

Stories: DMS-02–04, DMS-08–09, DMS-13.

Primary files: resources/views/workspace.blade.php, resources/views/layout.blade.php, public/assets/drive.js, app.js, item-details.js, folder-upload.js and loaded workspace styles.

1. Compare the available approved design with the rendered student navigation. If design retrieval is unavailable, record that dependency; functional/accessibility work can continue.
2. Verify all six personal destinations, nested My files navigation, breadcrumbs, + New actions, search, sorting, paging and grid/list state.
3. Resolve heading/help placement, long-name overflow, readable body text, empty/error/loading states and details/activity stale-response handling.
4. Verify dialogs through keyboard and pointer: focus, Escape, nested/repeated opens, background scroll restoration and visible required fields.
5. Exercise folder selection, sequential intake, partial failure/retry and failed post-upload folder assignment.
6. Review student/employee/admin journeys at 360, 768 and 1440 px; ensure personal users see only permitted controls.

Exit: saved desktop/mobile visual evidence and functional checks; no overlap or inaccessible controls; relevant frontend tests and Blade compilation pass. No claim of design matching without comparison.

### Step 3 — Prove document, grant and database correctness

Stories: DMS-05–07, DMS-10–12, DMS-14–17, DMS-19.

Primary files: DocumentService, DriveController, ShareController, AccountController, HistoryController, migrations and tests/Feature.

1. Cover file validation failures, checksum mismatch and private retrieval; verify old version bytes survive replacement.
2. Use real independent MySQL connections/processes to race same-owner filename/title creation, stale revisions and version numbering. Check different-owner reuse and Trash reservations.
3. Race shared writes against revocation, expiry, deactivation and Trash; verify viewer/commenter/editor restrictions and pinned-version behavior.
4. Race history duplicate events, changed replay and occupied revisions; check 12-before-11, tombstones/newer restoration and distinct source identities.
5. Inject write/commit/interruption failures. Verify no false receipt, exposed bytes or dangling referenced metadata; design a report-first orphan reconciliation procedure.
6. Rehearse the naming migration on a representative disposable MySQL copy. Check bytes/checksums, metadata disambiguation, memory/runtime and restore procedure. Dropping key columns does not undo renamed metadata.
7. Preserve server-assigned ownership, admin self-protection and inactive-session checks.

Exit: recorded isolated MySQL concurrency/failure results, safe winner/conflict behavior, consistent bytes/metadata and rehearsed migration recovery. Ordinary sequential tests are insufficient race evidence.

### Step 4 — Agree and validate integration contracts

Stories: DMS-18–19, DMS-21–22.

Owners: DMS architect, each source architect, enterprise architect and school policy owners.

For each of Employee Management, Online Admission, Enrollment and Payroll Management, obtain:

- Stable IDs/types and identity mapping; no name/email-only auto-link.
- Allowed fields/classifications, schema version and representative synthetic fixtures; no replicated secrets.
- Complete snapshot, consistent change checkpoint, revisions/event identity, deletes and delivery responsibilities.
- Credential scope, rotation/replacement, retry/backoff/jitter, error handling and reconciliation rules.
- Privacy/retention and access requirements; rollout/rollback and accountable owners.

Implement agreed schema/field validation and conformance tests at the existing application/integration boundary. Preserve /api/v1 and documented limits. Decide how connector replacement interacts with old receipts: current replay lookup is connector-account scoped and a retained record revision may conflict under a new account. Make any change explicit, migration-safe and tested.

Exit: four reviewed source contracts/fixtures, enforced schema/field rules, identity/access decisions and replay/rotation conformance evidence. Until agreement, use synthetic fixtures and keep live import blocked by that dependency.

### Step 5 — Deliver one complete source path

Stories: DMS-20–21; then DMS-22.

Select Admission or Enrollment according to actual contract readiness.

1. Choose the agreed producer push or DMS pull adapter; do not implement both speculatively.
2. Import a consistent initial snapshot with durable progress and matching change checkpoint.
3. Apply ongoing events through revision-safe intake. Sources own transactional outboxes and retry delivery in their systems.
4. Persist consumer work before advancing checkpoints; separate received-history and outgoing-document cursors.
5. Test restart, outage, duplicate/altered replay, late snapshot, delete/restore, token rotation and revoked credentials.
6. Reconcile expected counts/revisions and document incomplete or unavailable upstream history.

Exit: one synthetic then approved source end-to-end demonstration with restart/reconciliation evidence and operator runbook. Repeat for other ready sources; require restricted payroll access review before payroll rollout.

### Step 6 — Establish operations and controlled pilot

Stories: DMS-23–24.

1. Obtain approved retention, legal-hold, disposal and identity/access policies. Implement policy-driven controls only after decisions; Trash is not disposal.
2. Configure production MySQL, HTTPS, public/-only web root, debug-disabled settings, least-privilege database access, secrets and encrypted backups.
3. Back up and restore metadata/private bytes together, including required keys/configuration; validate checksums and agreed RPO/RTO in a drill.
4. Establish disk/capacity monitoring, safe logs, failed-delivery/conflict alerts, token rotation, escalation and support ownership.
5. Remove synthetic accounts/data from the production deployment through an authorized release procedure.
6. Review all MASTERPLAN.md release gates, accessibility/usability, pilot acceptance and rollback evidence.

Exit: accountable release sign-off with every required gate evidenced. No production-ready claim while a gate remains open.

## Verification commands

Run only in an isolated configured environment:

```sh
composer install
php artisan test --compact
php artisan view:cache
node tests/Frontend/folder-upload.test.cjs
php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml
git diff --check
```

Use node --check for changed JavaScript and targeted feature filters during implementation. The MySQL configuration resets school_dms_test; verify the resolved connection before execution. Never reset real/demo databases. Browser and multi-writer verification require additional evidence beyond these commands.

## Progress and evidence tracking

The initial documentation alignment and Step 2 folder/navigation fixes have been implemented. Full application acceptance above remains pending verification/execution. Existing baseline capabilities are documented implementation, not newly tested completion.

For each completed increment, record:

| Field | Required evidence |
|---|---|
| Scope | Step and DMS story IDs; relevant accepted decision |
| Change | Commit/PR and changed feature contract |
| Environment | PHP/database/browser versions and isolated database |
| Automated checks | Exact command, result and run link/log where available |
| Manual checks | Journey/viewports, observed result and artifacts |
| Limits | Missing design/source/policy access and unverified behavior |
| Acceptance | Accountable owner and criteria met |

Update MASTERPLAN.md story states only when their acceptance evidence exists. Prefer small reviewable increments: workspace usability, MySQL correctness, source contracts, first import, remaining sources, then operations.

## Execution record — 13 October 2026

Step 1 documentation alignment is completed for this increment; local runtime setup and broader verification remain incomplete.

- Linked this plan from the root README and documentation index.
- Replaced obsolete accordion guidance with actual workspace widget and contextual-help behavior, grounded in Blade/app.js/dialog-scroll.js inspection.
- Aligned the framework reference to Laravel 12.
- Documented integration-account-scoped receipt replay and the conflict boundary when an account is replaced, based on HistoryController.php inspection.
- Checked the existing [GitHub Actions run 38338162589](https://github.com/AAAAA13-sys/school-document-management/actions/runs/38338162589), for commit 4710c9c610d855205d1866bef5d38080d2afd578. Its job steps passed Composer installation, the SQLite application suite, folder-upload frontend regression and the MySQL suite. The workflow targets PHP 8.2 and MySQL 8.4; these results predate this increment.
- Local shell execution was rejected by automatic approval policy because sandbox approval is disabled. No local dependency installation, database setup, Blade compilation, browser review or multi-writer test was performed.
- Relative repository links and changed-file whitespace were checked before saving. Readback verification follows saving.

No product story is newly marked complete by these documentation changes. Next executable work is student workspace verification/refinement (Step 2) and MySQL contention/failure evidence (Step 3), subject to runtime/browser access. Credential replacement still requires conformance tests before its operational use is certified.

## Execution increment — baseline checks and folder navigation

Scope: Steps 1–2; DMS-02, DMS-08 and DMS-13. Changes retain the existing Laravel/jQuery architecture and server authorization.

- CI now compiles Blade with php artisan view:cache and checks every public/assets/*.js using node --check before running the existing application/frontend/MySQL suites.
- Folder-tree refresh derives visibility from available folders and the existing expansion button state. This fixes a tree remaining hidden after an empty workspace gains its first folder.
- File-list loading clears selection immediately, invalidating pending inspector responses before navigation completes or fails.
- Feature contract updated in docs/DRIVE-WORKSPACE.md; no product story marked Done.
- Previous documentation increment CI passed: [run 38338703290](https://github.com/AAAAA13-sys/school-document-management/actions/runs/38338703290), commit be7e7e867f3157b264a496e9ead5b550502116aa. Current increment CI results must be checked separately.

Remaining dependencies: local shell execution is rejected by sandbox approval policy; no browser session or approved Figma node is available for rendered comparison. MySQL multi-writer/failure drills, actual source contracts and school retention/recovery decisions remain open. Source/policy locations have been requested; no contracts or policy values are invented.
