# DMS manual testing — live checklist

Session: 23 October 2026, Australia/Perth (UTC+08:00).
Last update: 14:23 AWST. Status: UI-first pass prepared; awaiting Chrome reconnection.

Scope: manual Chrome testing against the local application at http://127.0.0.1:8011, using the latest SDD workflows. This is a new manual session; earlier automated results do not count as manual passes. Application code and configuration will not be fixed during this session.

## Live summary

| Passed | Failed | Blocked | Not run | Confirmed application bugs |
| --- | --- | --- | --- | --- |
| 3 | 2 | 1 | 35 | 2 |

Status meanings: PASS = manually observed expected behavior; FAIL = observed application defect; BLOCKED = prerequisite prevents the check; NOT RUN = no observation yet. Update each row after executing it. Findings remain open; no fixes are applied.

## Current execution order

1. **Barebones UI first:** layout, sidebar, workspace switching, lists/grid, search, detail panels, dialogs, keyboard and narrow-screen behavior. Use the existing synthetic read-only preview at http://127.0.0.1:8012/admin for protected UI. Record these as PREVIEW-ONLY, never as production authentication, persistence or authorization passes.
2. **Accounts next:** existing account sign-in, role landing and boundaries, account management screens.
3. **Hierarchical invitation movement last:** Super Admin → Admin → proposed Staff → Super Admin decision → individual activation; evaluate pending and rejected states and the proper account lifecycle.

User explicitly selected this order. Historical credential lookup is paused; no authorization to reset/reseed is inferred.

## UI-only pass (synthetic preview; separate from live application checklist)

| ID | UI check | Status | Observation |
| --- | --- | --- | --- |
| UI-01 | Administration overview structure, hierarchy and sidebar | PASS | PREVIEW-ONLY: overview renders desktop headings, stats, four workspace cards and activity. Collapse/expand control works; accessibility defects recorded separately. |
| UI-02 | All visible sidebar links and breadcrumbs | FAIL | PREVIEW-ONLY BUG-003: visible Requests and approvals link opens Preview page not found. Other links still being exercised. |
| UI-03 | Four initial workspace layouts and source identity | NOT RUN | — |
| UI-04 | Document list/grid toggles, search and empty states | FAIL | BUG-005: repository no-match search leaves blank table with stale Showing1to12of20 count and pagination. PMS filter narrows visible rows; further grid tests pending. |
| UI-05 | Folder navigation and detail close/back routes | NOT RUN | — |
| UI-06 | Document detail tabs, preview/blocked states and history | NOT RUN | — |
| UI-07 | Upload/details/share dialogs, labels, sizing and close behavior | NOT RUN | — |
| UI-08 | Account and connector list/form appearance only | NOT RUN | Functional account tests deferred to phase 2. |
| UI-09 | Audit/history/snapshot table readability | NOT RUN | — |
| UI-10 | Keyboard focus order, focus visibility and modal return | FAIL | BUG-004: collapsed icon-only navigation links have no accessible names in Chrome AX tree; DOM confirms empty rendered text and no aria-label/title. Further modal tests pending. |
| UI-11 | Narrow viewport sidebar, controls, tables and dialogs | NOT RUN | — |
| UI-12 | Visible stale copy, inconsistent states and broken links | NOT RUN | — |

## Session prerequisites

- Chrome requested explicitly. It connected for earlier signed-out checks, then disappeared from the tool inventory at the start of the UI-only phase. Reconnection requested.
- Direct Chrome connection attempt returned `Browser is not available: chrome`.
- Requested that the user connect/enable the Chrome extension in the intended profile.
- Signed-out live login/recovery/invitation screens have been observed; protected live workflows remain untested.
- Use clearly named synthetic test records, such as `MANUAL-QA-20261023-*`. Preserve existing documents and accounts.
- Do not send invitations to real people, publish real documents, change real staff privileges, or delete existing records as test data.
- Credential creation/change requires user entry. Mark dependent activation/recovery checks blocked if no suitable disposable test account is available.
- API-only requirements are listed separately; browser navigation alone cannot establish their correctness.

## Checklist

| ID | Scenario / manual steps | Expected result | Status | Actual result / evidence |
| --- | --- | --- | --- | --- |
| ENV-01 | Connect to Chrome; open local DMS | Chrome extension exposes an operable tab | PASS | Chrome extension connected (browser 3). App navigation initially returned ERR_CONNECTION_REFUSED; runtime startup in progress. |
| AUTH-01 | Open login while signed out | Login renders; no private content; labels and recovery link visible | PASS | Login rendered in Chrome with labeled Email/Password fields and recovery link; no private content. |
| AUTH-02 | Submit empty and invalid credentials | Clear validation; no private content or raw exception | PASS | Empty submit focuses Email with native required-field message. Synthetic invalid credentials return generic invalid-or-inactive error; password cleared. |
| AUTH-03 | Sign in with an existing authorized account | Correct role landing; signed-in identity visible | READY | Demo credentials seeded with Password123! across Super Admin (dms.admin@plpasig.edu.ph), Admin/Manager (ems@plpasig.edu.ph), and Staff/Employee (employee@plpasig.edu.ph). Ready for manual test. |
| AUTH-04 | Sign out; revisit protected page with Back/reload | Protected data requires authentication again | NOT RUN | — |
| AUTH-05 | Open Forgot password; submit an invalid/unknown address | Validation or generic recovery response; no identity disclosure or raw error | FAIL | BUG-001: unknown synthetic email submission replaces recovery form with bare 503 SERVICE UNAVAILABLE page; no explanation or navigation. |
| NAV-01 | Follow each visible sidebar link | Correct screen, heading and active state; no broken links | NOT RUN | — |
| NAV-02 | Switch every authorized workspace | Workspace name, source and content agree | NOT RUN | — |
| NAV-03 | Try an unassigned workspace and nonexistent workspace URL | Access denied/not found without foreign content | NOT RUN | — |
| NAV-04 | Exercise search with matching, unmatched and cleared terms | Correct results, usable empty state and reset | NOT RUN | — |
| NAV-05 | Switch list/grid and open/close document detail | Usable views; close returns to an appropriate list | NOT RUN | — |
| DOC-01 | Upload a benign synthetic small file | One document/version; progress and honest scan status | NOT RUN | — |
| DOC-02 | Attempt a file over 10 MB | Clear rejection; no false success or unusable duplicate | NOT RUN | — |
| DOC-03 | Re-upload identical synthetic content | Authorized duplicate warning; explicit keep-copy choice | NOT RUN | — |
| DOC-04 | Observe Pending, then scan outcome; attempt download | Unsafe/unscanned bytes blocked; Clean download works | NOT RUN | — |
| DOC-05 | Preview supported synthetic PDF/image/text | Correct file; readable preview; safe fallback for unsupported type | NOT RUN | — |
| DOC-06 | Add a new synthetic version; revisit history | Earlier version retained; numbers and current state correct | NOT RUN | — |
| DOC-07 | Create/rename/nest/move a synthetic owned folder | Correct hierarchy and location; no cycle allowed | NOT RUN | — |
| DOC-08 | Move/rename a synthetic document; reload | Correct persisted title/location and feedback | NOT RUN | — |
| DOC-09 | Star/unstar; open Starred | Accurate state and accessible action feedback | NOT RUN | — |
| DOC-10 | Add a synthetic comment; submit blank/overlong input | Comment appears once; validation enforces 2,000-character limit | NOT RUN | — |
| ACL-01 | Compare Staff, Admin and Super Admin navigation | Role-appropriate controls; no misleading actions | NOT RUN | — |
| ACL-02 | Open owned and unshared classification examples across roles | Internal/Confidential/Restricted visibility matches approved rules | NOT RUN | — |
| ACL-03 | Viewer/Commenter/Editor on synthetic explicitly shared record | Correct allowed actions; editing does not allow trash/move/reshare | NOT RUN | — |
| APR-01 | Staff requests sharing/classification/trash/restore | Pending request; live content/access unchanged; honest feedback | NOT RUN | — |
| APR-02 | Scoped Admin reviews a Staff request with reason | Correct proposal visible; approve/reject persists once | NOT RUN | — |
| APR-03 | Admin submits sensitive request; inspect as Super Admin | Correct next-role review; self-approval unavailable/denied | NOT RUN | — |
| APR-04 | Revisit decided or stale request; submit repeated decision | No duplicate application; understandable conflict | NOT RUN | — |
| SHR-01 | Create synthetic grant with missing/valid/>30-day expiry | Grant expiry is capped; invalid dates rejected clearly | NOT RUN | — |
| SHR-02 | Pin/revoke synthetic document grant; revisit as recipient | Correct version restriction; approved revocation removes grant access | NOT RUN | — |
| MEM-01 | Submit synthetic memo publication with Clean version/audience | Metadata validated; approval state visible; no premature release | NOT RUN | — |
| MEM-02 | Approve memo, add later draft, revisit as recipient | Recipient stays on released version until new approval | NOT RUN | — |
| ACC-01 | Open account/invitation lists and form controls | Usable role/workspace selection; no administrator password field | NOT RUN | — |
| ACC-02 | Propose disposable Staff invitation as Admin | Pending Super Admin approval; no account/access prematurely created | NOT RUN | — |
| ACC-03 | Attempt invitation delivery with current mail setup | Honest configured-transport result; no false delivery or raw exception | NOT RUN | — |
| ACC-04 | Invitee activation/replay/expiry and recovery lifecycle | Single-use recipient-bound link; user chooses credentials; replay blocked | FAIL | BUG-002: invalid token rejected with 410 but misleading Something is broken page, no expired-link explanation or recovery navigation. Valid activation/replay remains untested. |
| ACC-05 | Propose disposable account change/suspension and review | Pending until proper approval; authority/session rules enforced | NOT RUN | — |
| REG-01 | Inspect registry; create/rename/archive disposable workspace | Stable code; selectors update; archival respects access rules | NOT RUN | — |
| OPS-01 | Inspect audit, scan-security and authorized health screens | Correct scoped evidence; no bypass or secret exposure | NOT RUN | — |
| UX-01 | Keyboard-only navigation, focus, dialogs, errors and long text | Operable controls; visible focus; readable labels and errors | NOT RUN | Partial observation: Tab reaches brand, email, password, Sign in and Forgot password in order on login. Signed-in dialogs/errors/long text and visual focus checks remain untested. |
| UX-02 | Narrow Chrome window and inspect key screens | No inaccessible controls, clipped dialogs or unusable overflow | NOT RUN | — |

## Bug register

### BUG-001 — Recovery submission strands the user on a generic 503 page

- Severity: Medium (recovery unavailable, no actionable feedback).
- Role / URL: signed out; http://127.0.0.1:8011/forgot-password.
- Steps: open login → Forgot your password → enter `manual-qa-nonexistent@example.test` → Send recovery email.
- Expected: retain a usable recovery screen with a generic response or an honest mail-unavailable explanation and a way back to sign in.
- Actual: page is replaced by only `503 | SERVICE UNAVAILABLE`; no form, explanation, retry control or back link.
- Evidence: Chrome accessibility snapshot and screenshot observed during this session; page title Service Unavailable.
- Environment distinction: outbound mail was previously reported as log-only. Missing SMTP is a configuration dependency; the unhelpful full-page recovery failure is the observed UX defect. Actual delivery is not tested.
- Repeatability: one completed submission observed; will recheck after other signed-out cases if appropriate.
- Related check: AUTH-05. Status: OPEN, not fixed.

For each confirmed bug, record: ID, severity, affected role/URL, exact reproduction steps, expected versus actual behavior, evidence reference, repeatability and test row. Keep observations separate from suspected causes. Do not mark code-reading suspicions as manually reproduced bugs.

### BUG-002 — Invalid invitation link displays a generic broken-server error

- Severity: Low (misleading lifecycle feedback and dead end).
- Role / URL: signed out; `/invitations/accept/manual-qa-invalid-token`.
- Steps: open the above deliberately invalid invitation URL in Chrome.
- Expected: reject access, explain that the invitation is invalid/expired/already used and provide a sign-in or contact-administrator path.
- Actual: `Oops! An Error Occurred`, `410 Gone`, and `Something is broken... We will fix it as soon as possible.` No application navigation or invitation-specific explanation.
- Access control result: invalid token was correctly rejected; no account activation occurred. This is a UX defect, not a demonstrated access bypass.
- Evidence: observed Chrome accessibility snapshot and screenshot.
- Repeatability: one direct invalid-token visit. Related check: ACC-04. Status: OPEN, not fixed.

## Environment blockers and untested dependencies

| ID | Blocker | Impact | State |
| --- | --- | --- | --- |
| BLOCK-01 | Chrome extension initially unavailable | Resolved after user connected extension | RESOLVED |

## API / external checks outside ordinary UI navigation

OAS-to-ES packet intake/replay/revision ordering/exact-version retrieval and revocation; source connector authentication/throttling; real SMTP recipient delivery; full backup/restore drill; target-host load/availability; school/client sign-offs. Mark these untested unless separately exercised with appropriate authorized synthetic fixtures and observed evidence.

## Chronological execution log

- 14:09 AWST — Created checklist. Browser inventory inspected; Chrome unavailable. No fixes, application mutations or manual passes recorded.

- 14:14 AWST — Chrome connection confirmed. Local app returned ERR_CONNECTION_REFUSED; starting the existing web server only, without migrations or fixes.

- Chrome manual pass — AUTH-01 and AUTH-02 passed; AUTH-05 failed with BUG-001. No application code/configuration changed. Requested user sign-in for role-specific testing.

- Signed-out access check — direct /admin redirected to login without private content (supplementary pass; full logout/Back flow not yet run).
- Invalid invitation check — BUG-002 recorded; Chrome returned to login and retained for user sign-in. No fixes applied.

- Seeder check — existing seeded role accounts confirmed; current seeder delegates password to an unset local configuration. One repository test-demo credential attempt rejected. No credentials changed.
- Automatic approval review rejected reading historical plaintext credential candidates from Git as credential probing. Requested explicit user approval for the original demo-seeder credential read; no workaround attempted.

- Keyboard login check — visible controls reachable through Tab in expected order; broader UX-01 remains incomplete rather than counted as passed.

- User steering — barebones UI first, accounts second, hierarchical invitation flow third. Started existing read-only preview on8012; no migrations/seeding/code edits. Chrome currently unavailable again, so UI preview rows remain untested.

### BUG-003 — Preview navigation exposes unsupported approvals page

- Scope: PREVIEW-ONLY, not verified on live authenticated application. Severity: Low (UI test/demo coverage gap).
- Steps: open `http://127.0.0.1:8012/admin`; click Requests and approvals.
- Expected: preview screen or an explicitly unavailable/disabled preview action.
- Actual: `Preview page not found.` with Return to frontend link.
- Evidence: Chrome visible page and accessibility snapshot. No production routing failure is inferred. Status OPEN; no fix.

### BUG-004 — Collapsed navigation links lose accessible names

- Scope: shared sidebar as rendered in PREVIEW; authenticated live rendering not independently checked. Severity: Medium (accessibility).
- Steps: administration overview → Collapse navigation sidebar → inspect/focus icon navigation.
- Expected: each icon link retains an accessible name such as Overview or School repository.
- Actual: Chrome accessibility tree changes named links to unlabeled link URLs. DOM-backed observation confirms six visible navigation anchors with empty rendered text and no aria-label/title.
- Sidebar width settled at68px; no width defect claimed. Status OPEN; no fix.

- UI pass update — Chrome reconnected (browser4). Overview visually inspected; approvals preview dead end and collapsed navigation accessibility issue recorded immediately. No application changes.

### BUG-005 — Repository search has no empty-state message and retains stale totals

- Scope: PREVIEW-ONLY observation; live persistence/search not inferred. Severity: Low.
- Steps: administration → School repository → type `zzzz-ui-no-match` → allow debounce to settle.
- Expected: clear No matching documents feedback and matching result count/pagination.
- Actual: all rows disappear, table body is blank, footer still says Showing1to12of20 results and still offers page2.
- Clearing the search returns rows; selecting PMS narrows visible rows to payroll records. Evidence: Chrome screenshot and AX/DOM observations. Status OPEN; not fixed.

### BUG-006 — `/admin/workspaces` throws HTTP 500 (`Undefined variable $section in layout.blade.php`)

- Scope: LIVE authenticated application on `http://127.0.0.1:8011/admin/workspaces`. Severity: High (blocks Super Admin from viewing the Workspace Registry web page).
- Steps: Sign in as Super Admin (`dms.admin@plpasig.edu.ph` / `Password123!`), open `http://127.0.0.1:8011/admin/workspaces`.
- Expected: Workspace registry management screen displaying registered workspaces, active state, and add workspace form.
- Actual: HTTP `500 Server Error`. Laravel logs confirm: `ErrorException: Undefined variable $section` at `resources/views/layout.blade.php:68`.
- Root cause: `WorkspaceRegistryController::index` renders `workspaces-registry` without passing `$section`. `layout.blade.php` renders `<body data-section="{{ $section }}">` without null-coalescing fallback.
- Status: OPEN, not fixed (per QA strict constraint: document bugs and write automated tests, no production code changes).
