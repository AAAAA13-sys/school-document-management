# PLP Document Management System

> **Ongoing planning — 18 October 2026:** [Integration and implementation plan](docs/DMS-INTEGRATION-IMPLEMENTATION-PLAN.md), especially sections 14–15, records the latest role authority, sensitive-change approvals, invitation-only staff accounts, login/settings lifecycle, Figma handoff and team ownership. These requirements supersede earlier planning assumptions; they are **not implemented by this documentation update**. Tomas requested planning only. The current-behavior checklist below remains a separate record of the existing application.

Centralized document storage, version history and controlled sharing for Pamantasan ng Lungsod ng Pasig. The application can operate on its own and receive documents and transaction history from four ERP systems.

**Working checklist updated: October 17, 2026.** This README organizes the professor’s meeting feedback alongside the current implementation. Checked items describe existing functionality, not final evaluator acceptance. Unchecked items need decisions, implementation or presentation evidence. No overall completion percentage is claimed.

## 1. Background and purpose

School offices need a controlled place to store supporting files, preserve earlier versions and share authorized copies across departments. The DMS provides that repository; the originating ERP remains responsible for its business records and processes.

| Code | Connected system    | DMS responsibility                                                                      |
| ---- | ------------------- | --------------------------------------------------------------------------------------- |
| EMS  | Employee Management | Receive and organize approved personnel-related document categories and source history. |
| PMS  | Payroll Management  | Receive and organize approved payroll-related supporting files and source history.      |
| ES   | Enrollment          | Receive and organize approved enrollment-related supporting files and source history.   |
| OAS  | Online Admission    | Receive and organize approved admission-related supporting files and source history.    |

The actual document categories still need confirmation. These descriptions identify integration areas, not an approved inventory of files to collect. The DMS does not recalculate payroll, decide admissions, process enrollment or replace an ERP’s existing record screens.

### Project objectives

1. Store documents privately and preserve immutable file versions.
2. Give authorized users the correct viewing, editing and management access.
3. Support justified document sharing across offices without unnecessary copies.
4. Keep sensitive actions traceable through audit records.
5. Provide standalone document workflows and a scoped integration contract for the four ERPs.
6. Demonstrate functional jQuery/jQuery UI features and responsive Bootstrap interfaces.

## 2. Current output and implementation status

| Area               | Current implementation                                                                                                                   | Remaining work or limitation                                                                                                                |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Accounts and roles | Super Admin, Admin and Staff; Laravel policies/Gates plus route middleware enforce the server-side role matrix.                         | Confirm the wider faculty/college account model; demonstrate all roles with separate accounts.                                              |
| Source oversight   | Super Admin reads every ERP source and its audit log without editing; Admin manages one assigned source; Staff reads its own source.    | DMS is the application, not a document source; there is no personal `/my-workspace` repository.                                             |
| Uploads            | Plain multi-file drag/drop or file selection; filename titles; selectable classification; two simultaneous requests; retry failed files. | Up to 50 files per upload session and 10 MB per file. Any extension is accepted, but scan results still determine whether it can be served. |
| File security      | Private UUID storage, detected MIME metadata, SHA-256 integrity checks and queued local ClamAV scanning.                                 | Failed, pending and quarantined versions remain blocked; deployed scanner/worker health still needs verification.                           |
| Preview            | Local PDF.js viewer; common raster images; escaped text, CSV and JSON previews.                                                          | Office documents and archives remain download-only. No external document viewer receives private files.                                     |
| Organization       | Folders, rename, classification editing, move menu, document-to-folder drag/drop, stars, trash/restore and Back navigation.              | Department/group permissions are not implemented. Moving a file does not create a folder-sharing permission.                                |
| Sharing            | Named-user document and folder grants: Viewer, Commenter or Editor; optional expiry, revocation, pinned versions and folder inheritance. Folder grants expose Internal documents only; only Super Admin can share Restricted. | College/department groups still need a design and implementation.                                                                           |
| Integration        | Scoped connector tokens and `/api/v1` document/history endpoints, revision handling and replay protection. API read-back exposes Internal/Confidential only.                               | Each ERP team must supply and test its adapter. Working endpoints do not mean all four real ERPs are connected.                             |
| Duplicate handling | SHA-256 is recorded; idempotency keys prevent duplicate processing of the same submission retry.                                         | **Detecting the same file in separate uploads with different keys is still backlog work.**                                                  |
| Frontend           | Blade, Bootstrap, jQuery and jQuery UI; role-aware menus; four-second top-right notifications with a progress bar.                       | Complete the mobile/tablet/desktop checklist, consistent zebra table styling and screenshot evidence.                                       |

## 3. Professor feedback: decisions and action items

### A. Explain the sharing mechanism and its purpose

**Current mechanism:** an Admin managing an Internal/Confidential document grants a named account Viewer, Commenter or Editor access. Only Super Admin can create or revoke a share on a Restricted document. Sharing is limited by the existing school/campus scope; named grants may cross ERP source boundaries. Staff cannot change access permissions. There are no public links.

| Permission | Current document access                                                                                                                        |
| ---------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Viewer     | View metadata and preview/download available versions after scanning.                                                                          |
| Commenter  | Viewer access plus comments.                                                                                                                   |
| Editor     | Commenter access plus rename and new-version uploads; cannot move, trash, reshare or change classification merely because of the Editor grant. |

Role-based access also applies: Super Admin has read-only oversight of all four ERP repositories and the full audit log, except for Restricted sharing; an Admin manages Internal/Confidential content in its assigned ERP source and only owned Restricted documents; Staff can read Internal documents in its own source and only owned or explicitly shared Confidential/Restricted documents, while edits/deletes remain limited to owned or Editor-granted content. Individual grants are not the only access path for these management roles.

**Proposed presentation scenario, pending approval:** a college secretary shares an authorized evaluation/report document with a department head and selected faculty. One person reviews, one comments and one updates the working version. Explain why those recipients need that same document rather than another ERP record or an emailed copy.

- [ ] Confirm one real cross-department sharing scenario and the reason each recipient needs access.
- [ ] Prepare synthetic/sample documents and accounts for the demonstration.
- [ ] Demonstrate Viewer, Commenter and Editor as separate signed-in users.
- [ ] Show a denied action as well as a successful action.
- [ ] Demonstrate revocation/expiry and pinned-version restrictions.
- [ ] Explain school/campus boundaries and how proposed college/department groups would fit them.

### B. Confirm the actual document types and prevent ERP redundancy

Student grades and system reviews/evaluations were mentioned as examples in the feedback. They are **candidates to clarify**, not confirmed DMS requirements.

Complete this inventory with the professor and ERP teams before expanding the document model:

| Candidate document           | Originating office/system | Why DMS needs the file                                                       | Owner and recipients | Classification | Decision |
| ---------------------------- | ------------------------- | ---------------------------------------------------------------------------- | -------------------- | -------------- | -------- |
| Student grade document       | To confirm                | Explain the difference from the existing grade record/report feature.        | To confirm           | To confirm     | Pending  |
| System review/evaluation     | To confirm                | Confirm whether this is a shared working document, evidence or final report. | To confirm           | To confirm     | Pending  |
| Other actual office document | To confirm                | Identify a concrete repository/sharing need.                                 | To confirm           | To confirm     | Pending  |

- [ ] Identify the actual filenames/formats, business purpose and responsible office.
- [ ] Confirm which ERP remains the system of record.
- [ ] Remove document categories that simply duplicate an existing connected-system function without a separate repository need.
- [ ] Record recipients, versioning needs, expected volume and retention needs.
- [ ] Agree classification rules using realistic but synthetic demonstration files.

### C. Demonstrate access limits and classification

The current hierarchy is:

| Existing feature                | Super Admin                    | Admin                             | Staff                                                  |
| ------------------------------- | ------------------------------ | --------------------------------- | ------------------------------------------------------ |
| Manage Admin accounts and roles | Yes, within school/campus scope | No                                | No                                                     |
| Manage Staff accounts/passwords | Yes                            | Assigned ERP source only          | No                                                     |
| Manage ERP connectors           | Yes                            | No                                | No                                                     |
| View ERP documents              | All four sources and classifications, read-only | Assigned source Internal/Confidential; Restricted only if owner or shared | Own source Internal; Confidential/Restricted only if owner or shared |
| Upload/edit/delete documents    | No (read-only oversight)       | Assigned source Internal/Confidential; owned Restricted; Editor share edits content only | Own documents; Editor share edits content only |
| Manage folders                  | No (read-only oversight)       | Assigned source                   | No                                                     |
| Share documents/change grants   | Restricted documents only      | Assigned-source Internal/Confidential documents/folders; never Restricted | No                                                     |
| Change classification           | No                             | Own documents at any level; assigned-source Internal/Confidential documents otherwise | Owned documents; an Editor grant alone is insufficient |
| Audit access                    | Full scoped audit              | Assigned-source audit; hidden-document entries filtered | No administration audit screen                         |

Internal documents are readable by active Staff and Admins in their assigned ERP source and by Super Admin. Confidential documents are visible to their owner, the Admin of that source, Super Admin and explicit document recipients; Staff need ownership or an explicit document grant. Restricted documents are visible only to their owner, Super Admin and explicit document recipients. Named shares and folder grants may cross ERP sources as the documented exception, but folder grants widen Internal access only. Staff editing/deleting remains limited to owned documents; an Editor grant permits editing content but not deleting. Admins manage Internal/Confidential documents in their source and only owned Restricted documents.

**Classification is enforced server-side:** Internal, Confidential and Restricted all use one reusable visibility scope, and the document policy follows the same rules. Only Super Admin can create or revoke a Restricted document share; Super Admin remains read-only for other document writes. Hidden documents return 404 and do not appear in listings, search, counts, folder totals, downloads, duplicate warnings, API read-back or source audit entries. Existing explicit classifications are preserved; the database default for unspecified rows is Internal.

- [x] Implement the three-role hierarchy and restrict controls on the server as well as in the UI.
- [x] Provide classification selection during uploads and authorized editing afterward.
- [x] Agree what each classification means for viewing, editing and sharing; retention remains to be agreed.
- [x] Implement classification-based access rules for workspaces, search, folders, sharing, history/events, downloads, API read-back, counts and audit.
- [ ] Demonstrate Super Admin, Admin and Staff interfaces separately.
- [x] Prove Staff cannot access administration URLs or unshared documents through direct requests.

Approval workflows, 2FA/SSO, new notification channels and new system-settings modules were deferred from the earlier hierarchy pass. They are not implemented merely because they appeared in a role checklist.

### D. Model folder collaboration

The professor’s proposed CCS-faculty collaboration needs **folder/group permissions**, not just folders containing files. The original SDD lists folder-level sharing outside its initial scope; record this requested expansion explicitly before implementation.

- [ ] Confirm whether collaboration is granted to named users, department/college groups, or both.
- [ ] Model a sample group such as CCS faculty, its membership and responsible Admin.
- [ ] Define folder Owner/Viewer/Commenter/Editor capabilities.
- [ ] Decide whether children inherit permissions and how individual-document grants interact with inheritance.
- [ ] Specify what happens when a document moves between folders or a member leaves a group.
- [ ] Define revocation, classification conflicts and audit requirements.
- [ ] Revise the scope/specification, then implement and test the agreed model.

**Current limitation:** folder grants cover named accounts only and expose Internal documents; college/department group membership is not implemented.

### E. Implement duplicate-file detection

Existing integrity checks and submission replay protection are useful foundations, but they are not complete duplicate detection.

- [ ] Decide whether duplicates should warn, block, offer an existing file, or suggest a new version.
- [ ] Define comparison scope: personal repository, department/college, or school/campus.
- [ ] Compare content hashes for distinct uploads, including renamed copies.
- [ ] Do not expose private document names, owners or contents in duplicate warnings to unauthorized users.
- [ ] Test same bytes with different filenames, different bytes with the same filename, repeated requests and concurrent uploads.
- [ ] Confirm how intentional copies and cross-department sharing should be handled.
- [ ] Demonstrate the selected behavior after implementation.

### F. Re-evaluate account scope and target users

**Current state:** the DMS works without connected ERPs, but normal Admin/Staff accounts are assigned to an ERP source. Individual people can have named accounts; faculty/dean/college membership and multi-department membership are not modeled as separate access structures. Student DMS accounts remain outside the current scope.

| Candidate audience  | Decision needed                                                            |
| ------------------- | -------------------------------------------------------------------------- |
| Individual faculty  | Which personal/department repository and shared groups should they access? |
| Department heads    | Do they need document work, content administration, or both?               |
| Deans               | Is access college-scoped, read-only, or administrative?                    |
| College secretaries | Which staff accounts, folders and documents may they manage?               |
| Other school staff  | What access is needed when no ERP-specific role describes their work?      |

Job titles must not automatically grant Super Admin access. Map responsibilities to the existing hierarchy, then define the necessary organizational scope.

- [ ] Confirm the target audiences and a small set of realistic user journeys.
- [ ] Decide whether source-system assignment should remain mandatory for people.
- [ ] Define college/department membership separately from ERP connector identity.
- [ ] Decide whether one person may belong to multiple groups/departments.
- [ ] Update the model and onboarding screens after these decisions.
- [ ] Demonstrate that standard users never receive the Super Admin interface or its permissions.

### G. jQuery, Bootstrap and responsive presentation

| Technology            | Installed version              | Existing implementation evidence                                                            |
| --------------------- | ------------------------------ | ------------------------------------------------------------------------------------------- |
| Laravel / PHP / MySQL | Laravel 12 / PHP 8.2+ / InnoDB | Authentication, validation, scoped authorization, transactions and private files.           |
| Blade                 | Laravel views                  | Shared layouts, role-aware workspaces and dialogs.                                          |
| Bootstrap             | 5.3.3                          | Layout/grid, modals, dropdowns, forms and `.table` styling.                                 |
| jQuery                | 3.7.1                          | Event handling, filtering, AJAX uploads and document moves.                                 |
| jQuery UI             | 1.14.1                         | Draggable/droppable documents and folders, datepicker/autocomplete and toast progress bars. |
| PDF.js                | 6.4.299                        | Locally served PDF preview with page and zoom controls.                                     |
| ClamAV                | Local installation             | Automatic queued malware scanning; engine/signatures configured per installation.           |

- [x] Integrate functional jQuery and jQuery UI features.
- [x] Use Bootstrap components and responsive layout rules.
- [ ] Apply consistent Bootstrap striped/zebra table styling with alternating gray/white rows. Current `.table` usage alone does not finish this requirement.
- [ ] Check login, navigation, upload, document lists, tables, modals, previews and Back navigation at phone, tablet and desktop sizes.
- [ ] Repeat checks in portrait/landscape and with keyboard navigation.
- [ ] Capture final screenshots only after these checks; do not treat a responsive grid as proof of complete device coverage.

## 4. Report and demonstration deliverables

Use the professor’s report structure:

1. **Background:** office problem, intended users, existing ERPs and the reason a separate document repository is needed.
2. **Project objectives:** measurable repository, sharing, access-control, integration and frontend goals.
3. **Output:** implemented screens and workflows, screenshot/code explanations, test results and remaining limitations.

Include this evidence set. Use synthetic/redacted data and never expose passwords, connector tokens or real student/employee records.

| Screenshot or demonstration                                | Code to explain                                                                                                              | Rationale to include                                                                       |
| ---------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------ |
| Different role dashboards/navigation                       | `resources/views/layout.blade.php`, `app/Policies`, `app/Providers/AppServiceProvider.php`, `routes/web.php`                  | Different controls plus policy/Gate enforcement; hidden controls alone are insufficient.    |
| Multi-file upload and classification                       | `resources/views/partials/upload.blade.php`, `public/js/dms.js`, `app/Http/Requests/UploadRequest.php`                       | jQuery queues separate uploads; validated metadata and automatic scanning.                 |
| Drag a document into an existing folder; use the move menu | `public/js/dms.js`, `resources/views/partials/documents.blade.php`, `app/Http/Controllers/DocumentController.php`            | jQuery UI interaction with the same validated/revision-checked server operation.           |
| Four-second top-right notification                         | `public/js/dms.js`, `resources/views/layout.blade.php`                                                                       | jQuery UI progress bar provides visible feedback and dismissal timing.                     |
| Sharing and a denied action                                | `app/Services/Access.php`, `app/Http/Controllers/ShareController.php`                                                        | Named-user grants, role limits, scope and version restrictions.                            |
| Phone/tablet/desktop tables and dialogs                    | `resources/views/workspace.blade.php`, `resources/views/admin.blade.php`, `public/css/dms.css`                               | Bootstrap layout/table use and tested responsive behavior.                                 |
| Preview, classification change and version history         | `resources/views/document.blade.php`, `app/Services/DocumentService.php`                                                     | Inspect content without downloading; access/checksum/scan gates still apply.               |
| Folder/group collaboration and duplicate detection         | Final implementation, once completed                                                                                         | Present as backlog/design work until functional and tested.                                |

### Short code examples for the report

These excerpts identify current implementations. Include the surrounding validation/permission code in the final explanation rather than implying client-side code provides security.

```javascript
// jQuery UI: initialize the notification countdown.
const countdown = $(box).find(".toast-countdown").progressbar({ max: 4000, value: 4000 });
```

The timer updates the progress value and dismisses the toast after four seconds. This demonstrates a working jQuery UI widget rather than only an included script.

```javascript
// jQuery UI: documents can be dragged by their file cell.
$('.document-row[data-can-move="true"]').draggable({
  handle: ".file-cell",
  // See public/js/dms.js for the helper and drop implementation.
});
```

The folder drop calls the existing move operation. Laravel checks ownership/management rights, destination scope and the document revision before committing the change.

```html
<!-- Bootstrap markup already used for tabular data. -->
<div class="table-responsive">
  <table class="table" data-filter-table>
    <!-- Headers and authorized rows -->
  </table>
</div>
```

For the professor’s zebra-table requirement, add and verify `.table-striped` with the project’s gray/white styling. That change remains an unchecked action above; this example does not claim it is already applied.

### Suggested demonstration sequence

1. Explain the confirmed document use case and its difference from the originating ERP.
2. Sign in as Staff; upload sample files, select classification and preview a clean document.
3. Demonstrate a new version, authorized editing, search and moving an owned file.
4. Sign in as Admin; show source-scoped staff/content management and named-user sharing.
5. Use recipient accounts to demonstrate allowed viewing/editing and a blocked action.
6. Sign in as Super Admin; show system administration, read-only ERP oversight, full audit and account management.
7. Show folder collaboration and duplicate detection only once implemented; otherwise identify the exact remaining work.
8. Show mobile/tablet/desktop screenshots and explain the actual Bootstrap/jQuery code.

## 5. Prioritized work board

| Priority | Action                                                                                | Completion evidence                                                                    |
| -------- | ------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| P0       | Confirm document inventory, target users and the reason for cross-department sharing. | Reviewed inventory and agreed user journeys.                                           |
| P0       | Agree organizational/account scope and classification access policy.                  | Permission matrix covering roles, colleges/departments and file sensitivity.           |
| P0       | Specify folder/group collaboration and duplicate behavior.                            | Approved design with inheritance, revocation, move and privacy rules.                  |
| P1       | Implement and test the approved gaps.                                                 | Working folder collaboration and duplicate-detection scenarios with regression checks. |
| P1       | Complete zebra tables and responsive/role UI checks.                                  | Phone/tablet/desktop evidence and denied-action demonstrations.                        |
| P1       | Write the report and capture final screenshot/code pairs.                             | Background, objectives, output and limitations sections.                               |
| P2       | Rehearse the presentation and coordinate integration demonstrations.                  | Tested demo accounts, sample files and confirmed evaluator arrangements.               |

Owners and estimates have not been assigned. Do not mark a decision task complete without the agreed answer, or an implementation task complete without a working demonstration/check.

## 6. Presentation schedule

| Date             | Meeting note                          | Status                                                                 |
| ---------------- | ------------------------------------- | ---------------------------------------------------------------------- |
| October 24, 2026 | Evaluator unavailable.                | Availability note; do not schedule an evaluation without reconfirming. |
| October 31, 2026 | Preferred fallback presentation date. | Tentative; confirm with evaluator.                                     |
| November 7, 2026 | Alternative presentation date.        | Tentative; confirm with evaluator.                                     |

- [ ] Confirm the final date/time and online versus face-to-face modality.
- [ ] Confirm whether frontend and backend evaluations will be presented separately.
- [ ] Assign presenters, demo roles and report responsibilities.
- [ ] Prepare a local demo and recorded/screenshot evidence if the live environment is unavailable.

These notes are not a confirmed booking or an automated reminder.

## 7. Run the current system

Run commands from the application folder containing `artisan` and `composer.json`.

```powershell
composer install
npm ci
npm run build
```

Follow [Standalone setup](docs/SETUP.md) for the database, existing/new `.env`, application key and local ClamAV configuration. Preserve an existing application key and database; do not rerun seeders to repair an existing login because the account seeder overwrites its predefined accounts.

On Windows, the existing launcher starts the local stack:

```powershell
.\start.bat
```

The application normally runs at `http://127.0.0.1:8011`. Its database scan worker and scheduler must run for automatic scanning and retries. `npm run preview` at port 8012 is a synthetic read-only frontend, not the standalone system or proof of backend behavior.

For a new installation, the bootstrap command creates a Super Admin without overwriting an existing account:

```powershell
php artisan dms:admin your.admin@example.edu --school=PLP --campus=Main
php artisan dms:status
```

### Verification commands

```powershell
php artisan test
npm ci
npm test
npm run build
npm run format:check
php scripts/check-encoding.php
php vendor/bin/phpunit -c phpunit.mysql.xml
```

The PHP test configuration uses an isolated in-memory SQLite database by default. MySQL concurrency, restore and real-engine ClamAV checks have separate environment requirements; record skipped checks instead of claiming those checks passed. GitHub Actions runs the same checks on pushes and pull requests, plus a post-merge smoke test and a scheduled real-ClamAV workflow. See [Continuous integration](docs/CI-CD.md), [Verification](docs/VERIFICATION.md) and [Operations](docs/OPERATIONS.md). This README revision is documentation work and does not itself constitute a new full-system test run.

## 8. Project references

- [Software Design Document](docs/DMS-SDD.pdf): original scope; record professor-requested changes explicitly.
- [Application guide](docs/AGENTS.md): architecture and current development constraints.
- [Setup](docs/SETUP.md): standalone installation, workers and scanning.
- [User guide](docs/USER-GUIDE.md): reference for document workflows; reconcile with the final screens before submission.
- [Frontend notes](docs/FRONTEND.md): presentation implementation reference.
- [ERP API contract](docs/API.md) and [OpenAPI JSON](docs/openapi.json): integration behavior.
- [Implementation plan](docs/IMPLEMENTATION_PLAN.md): original phased plan, not evidence that every phase is finished.
- [Operations](docs/OPERATIONS.md) and [Verification](docs/VERIFICATION.md): operational procedures and verification context.
- [Continuous integration](docs/CI-CD.md): GitHub Actions, isolated MySQL tests, real-ClamAV checks and post-merge smoke verification.
- [Figma reference](https://www.figma.com/design/n44HHt77BnanifVreeSP7S/dms?node-id=0-1): supplied design reference.
