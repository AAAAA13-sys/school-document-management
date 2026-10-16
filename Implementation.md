# Implementation plan

Updated 13 October 2026. Planning document based on the repository's current documentation and selected code review; no new application tests, visual checks, migrations or live integrations were executed for this review.

## Purpose and authority

[MASTERPLAN.md](MASTERPLAN.md) owns scope, DMS-01 through DMS-24 and production release gates. [AGENTS.md](AGENTS.md) owns engineering rules. This document turns that backlog into ordered implementation tasks with dependencies and evidence. It does not replace either document or declare planned work complete.

Read [docs/README.md](docs/README.md), [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md), [docs/RBAC.md](docs/RBAC.md), [docs/DRIVE-WORKSPACE.md](docs/DRIVE-WORKSPACE.md), [docs/STUDENT-WORKSPACE.md](docs/STUDENT-WORKSPACE.md), [docs/HISTORY-SYNC.md](docs/HISTORY-SYNC.md), [docs/INTEGRATION.md](docs/INTEGRATION.md) and [docs/WORKFLOW-OWNERSHIP.md](docs/WORKFLOW-OWNERSHIP.md) for current contracts.

The SDD has since been read for its requirements: FR-01…FR-09 and NFR-01…NFR-09 are split into [docs/SDD-REQUIREMENTS.md](docs/SDD-REQUIREMENTS.md). Hosting, privacy, service-level and source-contract questions in it remain open, and the SDD's open placeholders were preserved rather than filled in.

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

## Verified increment — upload failure recovery

Step 3 / DMS-17 partial evidence: tests/Feature/DocumentWorkflowTest.php now injects a failed private-storage write and a transactional event-write failure after writing replacement bytes. Assertions verify metadata/audit/events/receipts roll back, uncommitted bytes are cleaned up, and the original version content/revision remains intact.

[CI run 38339639050](https://github.com/AAAAA13-sys/school-document-management/actions/runs/38339639050) passed on commit 336455072b27e25d80be7da702418d17670775bc: Blade compilation, all application JavaScript syntax checks, SQLite application tests, folder-upload frontend regression, and the isolated MySQL feature/unit suite. The earlier navigation/CI increment also passed [run 38339398081](https://github.com/AAAAA13-sys/school-document-management/actions/runs/38339398081).

The first recovery test attempt exposed a test-injection issue: replacing the service binding after the first request did not replace the controller's existing dependency. Failure injection now occurs at the database event write; no production upload behavior was changed for this test.

| Plan step | Evidence and remaining work |
|---|---|
| 1 | Documentation alignment and automated baseline verified in CI; local environment inspection remains unavailable |
| 2 | Folder visibility/navigation selection fixes committed; desktop/mobile/Figma/keyboard acceptance remains open |
| 3 | Storage-write and post-write transaction failure regressions pass on SQLite/MySQL; actual commit failure, process interruption, orphan reconciliation, migration rehearsal and competing-writer checks remain open |
| 4 | Approved source contracts, schema/field grants and identity mapping document locations requested; not supplied |
| 5 | Requires Step 4 plus source snapshot/change delivery contract |
| 6 | Requires school-approved retention/hold/disposal and recovery targets plus deployment access |

No release gate is declared complete from these partial results. Continue with runtime/browser and contract dependencies when available; do not substitute guessed source contracts or school policy for accepted decisions.

## Data increment — personal workspaces were empty because records had no owner

Stories: DMS-02, FR-02, FR-09. Found while verifying the FR/NFR register against the running system.

Cause: `DocumentService::scope()` filters personal roles with `where('owner_user_id', $a->id)`. Uploads assign that correctly, but `DemoSeeder` inserted every seeded document and folder without an owner, so all seeded rows held `owner_user_id = NULL`. No owner ever matches that predicate, so employee and teacher accounts signed in to a permanently empty workspace: `/workspace/stats` returned `total 0`, `/workspace/drive` returned no items and `/workspace/folders` returned no folders. The scope rule was correct; the seeded data contradicted it.

Changes:

- `database/seeders/DemoSeeder.php` — captures each seeded user's id, maps each source to its owning office role (Employee Management to employee, Online Admission and Enrollment to registrar, Payroll Management to payroll), and inserts `owner_user_id` plus a `folder_id` on the seeded records. Two nested folders, "Staff Records" and "Contracts 2026", are now seeded under the employee account in the Employee Management source so FR-02 nesting is visible on a fresh install; previously the seeder created no folders at all. The existing `documents` existence guard is untouched, so a re-run stays a no-op.
- The running local `school_dms` database was corrected with the same mapping for its 8 existing documents. No other column, file bytes, version rows or audit history were altered, and no table was dropped or reset.

Verified against the running system: `/workspace/stats` for the employee account now returns `total 2, available 2` instead of all zeroes, `/workspace/drive` returns the Employee Management documents, `/workspace/folders` returns the employee's folder, and the Home page renders populated "Suggested documents" and "Recent" sections with real titles, sources and availability. `php -l` passes on the seeder.

Limits. Only the employee workspace was previously empty; the office roles were unaffected because `scope()` does not filter them by owner. Seeded folders already owned by the administrator were deliberately left with the administrator as owner rather than reassigned, so the employee sees only the employee account's own folder, which is the intended personal-scope behaviour. Scan statuses were not changed: all seeded versions were already `Clean`, and `docs/SDD-ALIGNMENT.md` states historical clean results are retained and only provenance-uncertain imports are rescanned. Re-seeding from scratch now produces `Pending`, because the seeder's existing version insert already used that value; existing rows were not rewritten.

This corrects data, not a defect in the scope rule. `tests/Frontend/staff-workspace.test.cjs` still fails for the pre-existing unrelated reason recorded above; `node tests/Frontend/folder-upload.test.cjs` passes and `node --check public/assets/drive.js` passes. No application, MySQL or accessibility suite was run.

## UI increment — My files row and folder-path control

Story: DMS-02 (workspace navigation). Reported as the "My files" sidebar button and its folder-path expander looking unfinished.

Cause: the expander was the literal text glyph `⌄` inside `#folders-toggle`, positioned by `top:7px` and rotated with `.folders-toggle[aria-expanded=true]{transform:rotate(180deg)}` on the button itself. That produced a thin, low-contrast character sitting slightly above the pill's optical centre, with no visible hit area.

Changes:

- `resources/views/workspace.blade.php` — removed the `⌄` text node from `#folders-toggle`. The button keeps `aria-label`, `aria-expanded` and `aria-controls`, so removing the glyph also removes a meaningless "downwards arrow" announcement for screen readers; the existing label swap in `drive.js` still applies.
- `public/assets/workspace-refresh.css` — the chevron is now drawn with a `::before` pseudo-element (9×9, 2px `border-right`/`border-bottom`, rotated 45°). The button is a 34×34 grid centred with `top:50%` + `translateY(-50%)`, so the 16px `.nav-item` bottom margin can no longer push the chevron off-centre, and the 180° rotation now applies to the pseudo-element instead of the button, which no longer conflicts with centring. Added a 0.18s rotation transition, a hover background and a visible `:focus-visible` outline.
- `public/assets/staff-workspace.css` — chevron colour `#5d6d63` so it reads clearly on the light staff sidebar, turning `var(--green)` when the My files row is active and `var(--deep)` on hover.

Verified in the browser against the local server, reusing one tab: the chevron computed to a 9×9 element with 2px borders and the expected 45° matrix; its vertical centre matched the My files pill's centre to 0px; the button's text content is empty; `aria-label` and `aria-expanded` still flip between "Expand"/"Collapse My files folders"; the folder tree still shows and hides; and the loaded stylesheet contained all three new `.folder-card`/`.files-navigation` rules. A screenshot confirmed the pill and nested folder list render cleanly in the light staff theme.

`node --check public/assets/drive.js` passed, `php artisan view:cache` compiled, and `node tests/Frontend/folder-upload.test.cjs` passed. The pre-existing `staff-workspace.test.cjs` VM failure is unchanged. No application, MySQL or accessibility suite was run.

## UI increment — open a folder by double-clicking the whole card

Story: DMS-02 / DMS-08 (workspace navigation). Reported as "opening folders is weird; the whole card should be double-clickable, not just the name".

Cause: only the name button was interactive. `render()` built each folder card as a `<button class="drive-folder drive-name">` plus inert `<span>` cells for source, date and size, and the delegated click handler routed any `.folder-card` match to `inspectFolder`, which only fills the details panel. Opening the folder required the sidebar, or an extra "Open folder" click inside that panel. File cards already double-clicked to open via `.file-details`; folders had no equivalent.

Changes, all in existing files:

- `public/assets/drive.js` — folder cards now carry `data-id` and a `title` hint; the card `⋮` menu gained an "Open folder" item next to "Rename folder". A delegated `dblclick` handler on `.folder-card` calls the existing `openFolder()` helper and ignores events originating inside `.drive-menu`, `.folder-open` or `.folder-rename`, so double-clicking the menu does not navigate. `.folder-open` is wired to the same helper.
- `public/assets/workspace-refresh.css` — `cursor:pointer`, a hover border/shadow and a `:focus-within` border so the card reads as actionable.

Single click still opens details rather than navigating, which keeps the existing inspector path intact. The "Open folder" menu item and the inspector button remain the keyboard and pointer equivalents; double-click is an additional shortcut, not the only route.

Verified in the browser against the local server on one reused tab: double-click on the card body (the source cell, not the name button) navigated to the folder and revealed the Back button labelled "Back to My files"; double-click on the name button also opened it; double-click on the `⋮` summary correctly did not navigate and left breadcrumbs at "My files"; the "Open folder" menu item opened the folder; `cursor` computed to `pointer` and the menu listed "Open folder" then "Rename folder". `node --check public/assets/drive.js` passed, `php artisan view:cache` compiled, and `node tests/Frontend/folder-upload.test.cjs` passed. The pre-existing `staff-workspace.test.cjs` VM failure described below is unchanged and unrelated.

The temporary folder created to exercise this was deleted afterwards.

## UI increment — folder Back button

Story: DMS-02 / DMS-08 (workspace navigation). Reported by a user as "whenever I open a folder I cannot close it".

Root cause: entering a folder was only reversible through the breadcrumb's `My files` text, which is styled by `#drive-crumbs>.btn{border:none;font-size:16px;padding:0 4px}` and therefore reads as static bold text rather than a control. There was no explicit way to step up from a nested folder, and opening a folder from the sidebar tree replaced the whole view.

Changes, all in existing files:

- `resources/views/workspace.blade.php` — added `#drive-back` before `#drive-crumbs`, wrapped both in `.drive-heading`. The button carries `type="button"`, `aria-label` and `hidden` so it cannot submit or appear at the root.
- `public/assets/drive.js` — added `crumbs` state, a `renderBack()` helper, and an `openFolder(id)` helper that replaces four duplicated "set folder, clear search, drop home mode, reload" sequences. `renderBack()` shows the button only when `location==='files'` and a folder is open, and labels it with the parent folder name. The parent is taken from the second-to-last breadcrumb the API already returns, so no endpoint changed.
- `public/assets/workspace-refresh.css` — `.drive-heading` flex row, non-shrinking button, visible focus ring, wrapping breadcrumbs.

Back steps to the parent folder, and to `My files` for a top-level folder. It is hidden at the root and on Home, Recent, Starred, Shared with me and Trash.

Verification actually performed, in a browser against the local server: inside a folder the button is visible with label `Back to test folder` and breadcrumbs `My files / test folder / Nested Child`; activating it lands on `My files / test folder` with the button still visible, correctly indicating one level is still open. At the root it is hidden. `node --check public/assets/drive.js` passed and `php artisan view:cache` compiled.

`node tests/Frontend/folder-upload.test.cjs` passes. `tests/Frontend/staff-workspace.test.cjs` fails with `ReferenceError: document is not defined`, which is pre-existing and unrelated: it loads only `public/assets/staff-workspace.js` into a VM context that supplies `$` but no `document`, and that file was not modified here. No application test suite, MySQL suite or accessibility review was run.

Test folders and the probe document created while verifying this increment were removed afterwards. `test folder` was left in place because it was not created during this work.

## Documentation increment — FR/NFR requirement register split

Scope: Step 1 documentation alignment. Documentation only. No application code, schema, test or runtime change.

The manager instruction was to split the SDD's coded requirements into functional and non-functional parts so each can be reviewed and assigned separately, and to place them in the repository.

- Added `docs/SDD-REQUIREMENTS.md`. It holds FR-01…FR-09 and NFR-01…NFR-09 as two independent tables, copied verbatim from SDD §5.1 and §5.2 (pages 6-8), with each SDD priority kept as written.
- Added indexes for the SDD's other code series so they are not mixed into the requirement tables: OBJ-01…OBJ-05 (§3.3), INT-01…INT-06 (§9), R-01…R-06 (§13.1) and AD-01…AD-07 (§13.3).
- Recorded SDD Appendix B traceability plus its gap: NFR-04, NFR-05, NFR-07, NFR-08 and NFR-09 have no component or test owner in the SDD. The gap is documented, not filled in.
- Linked the register from `docs/README.md` and from the authority note in `MASTERPLAN.md`.

Verification: PDF text was extracted with `pypdf` 6.21.0 and FR/NFR wording was compared against SDD §5.1/§5.2. No application tests, Blade compilation, JavaScript syntax check or browser review was run; none was requested. Relative links and paths were checked by reading the saved files back. No release gate or backlog story changed state.

Limits and cautions:

- The FR/NFR to DMS-xx story mapping in `docs/SDD-REQUIREMENTS.md` is **proposed**, not SDD content and not approved. The SDD contains no such mapping.
- NFR-05 (availability), NFR-07 (privacy) and NFR-08 (auditability) have no agreed target value in the SDD. They are marked unagreed rather than assigned a value.
- FR/NFR MoSCoW priorities are the SDD's own proposal and remain unconfirmed by the client. The SDD assigns no priority to NFRs at all.
- The SDD cover reads v0.1 draft. The user's 16 October designation of it as the final implementation plan is unchanged; this register does not alter that.
- `Implementation.md` previously stated that the SDD had not been read. That is now superseded for content extraction: the PDF has been read for requirements. Hosting, privacy, service-level and contract questions in it remain open.

No product story is newly marked complete by this documentation change.

## Backend increment — preserve upload errors when cleanup fails

Step 3 / DMS-17: DocumentService now catches deletion errors during failed-upload cleanup and handles deletion returning false. It preserves the original intake exception/response and logs a reconciliation warning containing only the generated storage key. This applies to all callers of the shared upload service; no new storage abstraction or public endpoint is added.

Parameterized regressions cover false-return and throwing cleanup adapters, original 503 response preservation, rolled-back metadata/events/audit/receipts, and the warning's limited context. Verified by [CI run 38340034085](https://github.com/AAAAA13-sys/school-document-management/actions/runs/38340034085) on commit a89ec3164058dcbbed81c0651c2f45bebd5b999d: SQLite and MySQL suites, frontend regression, Blade compilation and JavaScript syntax all passed. Process interruption, outer transaction commit failures, automated orphan reconciliation and production recovery drills remain open.
