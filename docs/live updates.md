# Live Manual Testing in Google Chrome — Real-Time Updates

**Target System:** Live Application on `http://127.0.0.1:8011` (MySQL Database, Private Storage, Live Sessions)  
**Browser:** Google Chrome (Interactive Session via Playwright/Browser Subagent)  
**QA Lead:** Autonomous QA & Validation Lead  
**Session Started:** 23 October 2026, 16:51 AWST  
**Last Updated:** 23 October 2026, 17:02 AWST  
**Session Recording:** [chrome_manual_qa_live_1792745532127.webp](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/73e318b3-9b34-46c9-8d35-44cda488f4a7/chrome_manual_qa_live_1792745532127.webp)  
**Browser Scratchpad:** [scratchpad_tu0o7ksd.md](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/73e318b3-9b34-46c9-8d35-44cda488f4a7/browser/scratchpad_tu0o7ksd.md)  

---

## Executive Summary & Progress

| Phase | Description | Target User / Role | Status | Findings / Evidence |
| :---: | :--- | :--- | :---: | :--- |
| **Phase 1** | Super Admin Authentication, Admin Portal, Accounts & Diagnostics | `dms.admin@plpasig.edu.ph` | **PASS / DEFECT FOUND** | Verified `/admin`, `/admin/accounts`, `/admin/health`, `/approvals`. Confirmed critical **BUG-006** (HTTP 500 on `/admin/workspaces`). |
| **Phase 2** | Scoped Admin (EMS) Workflow, Document Inspection, Detail Tabs & Live Commenting | `ems@plpasig.edu.ph` | **PASS** | Landed on `/workspaces/ems`, inspected doc `99166390-5f63-4677-84c6-c3237c5a5818`, navigated Overview/Versions/Sharing/Activity tabs, posted live comment. |
| **Phase 3** | Staff (EMS) Access, Document Visibility & Admin Boundary Enforcements | `employee@plpasig.edu.ph` | **PASS** | Landed on `/workspaces/ems`. Attempted navigation to `/admin` -> blocked with **HTTP 403 Forbidden**. |
| **Phase 4** | Cross-Source Isolation Verification & Direct URL Tampering | `pms.staff@plpasig.edu.ph` | **PASS** | PMS Staff landed on `/workspaces/pms`. Attempted direct URL access to EMS document -> blocked with **HTTP 404 Not Found**. |

---

## Detailed Test Matrix & Live Checklist

### Phase 1: Super Admin Portal & System Administration
- [x] **ENV-01**: Chrome connected to live application on `http://127.0.0.1:8011`
- [x] **AUTH-01**: Open `/login` while unauthenticated (verified clean login card, labels, CSRF token)
- [x] **AUTH-02**: Sign in as Super Admin (`dms.admin@plpasig.edu.ph` / `Password123!`) in Chrome
- [x] **DASH-01**: Verify landing on `/admin` with KPI metric counters, workspace cards, quick actions ([Screenshot](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/73e318b3-9b34-46c9-8d35-44cda488f4a7/super_admin_dashboard_1792745627179.png))
- [x] **ACCT-01**: Navigate to `/admin/accounts`, inspect user table, roles, statuses, and account actions ([Screenshot](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/73e318b3-9b34-46c9-8d35-44cda488f4a7/admin_accounts_1792745646918.png))
- [x] **HLTH-01**: Navigate to `/admin/health`, verify storage, database, scanner, and background worker status ([Screenshot](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/73e318b3-9b34-46c9-8d35-44cda488f4a7/admin_health_1792745659222.png))
- [x] **WORK-01**: Navigate to `/admin/workspaces`, verify workspace management registry -> **Observed HTTP 500 Server Error (BUG-006)** ([Screenshot](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/73e318b3-9b34-46c9-8d35-44cda488f4a7/bug_006_workspaces_500_1792745670692.png))
- [x] **APPR-01**: Navigate to `/approvals`, inspect workflow requests and approval status queues ([Screenshot](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/73e318b3-9b34-46c9-8d35-44cda488f4a7/admin_approvals_1792745678810.png))
- [x] **AUTH-03**: Sign out cleanly via user profile menu, verify session invalidation and redirect to `/login`

### Phase 2: Scoped Admin (EMS) Document Management & Workflow
- [x] **AUTH-04**: Sign in as EMS Scoped Admin (`ems@plpasig.edu.ph` / `Password123!`)
- [x] **WS-01**: Verify landing / redirect to EMS Workspace (`/workspaces/ems`) ([Screenshot](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/73e318b3-9b34-46c9-8d35-44cda488f4a7/ems_workspace_1792745784397.png))
- [x] **WS-02**: Verify EMS document list, table columns (Title, Reference, Classification, Status, Modified)
- [x] **DOC-01**: Navigate to document detail screen `/documents/99166390-5f63-4677-84c6-c3237c5a5818` ([Screenshot](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/73e318b3-9b34-46c9-8d35-44cda488f4a7/document_detail_1792745804992.png))
- [x] **DOC-02**: Verify Overview tab (metadata, download link, preview button)
- [x] **DOC-03**: Verify Versions tab (SHA-256 checksum verification, author, upload date)
- [x] **DOC-04**: Verify Sharing tab (source default permissions, share modal)
- [x] **DOC-05**: Verify Activity tab, submit live comment: *"Live manual QA verification via Google Chrome"* ([Screenshot](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/73e318b3-9b34-46c9-8d35-44cda488f4a7/comment_posted_1792745884671.png))
- [x] **AUTH-05**: Sign out cleanly

### Phase 3: Staff (EMS) Role Boundary & Document Access
- [x] **AUTH-06**: Sign in as EMS Staff (`employee@plpasig.edu.ph` / `Password123!`)
- [x] **WS-03**: Verify landing on `/workspaces/ems` (assigned tenant workspace)
- [x] **DOC-06**: Verify visibility of EMS documents within authorized department
- [x] **SEC-01**: Direct URL manipulation test: attempt navigation to `/admin` -> **Blocked with HTTP 403 Forbidden** ([Screenshot](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/73e318b3-9b34-46c9-8d35-44cda488f4a7/staff_admin_forbidden_1792745953811.png))
- [x] **SEC-02**: Direct URL manipulation test: attempt navigation to `/admin/accounts` -> **Blocked with HTTP 403 Forbidden**
- [x] **AUTH-07**: Sign out cleanly

### Phase 4: Multi-Tenant / Cross-Source Isolation Boundary
- [x] **AUTH-08**: Sign in as PMS Staff (`pms.staff@plpasig.edu.ph` / `Password123!`)
- [x] **WS-04**: Verify redirect to `/workspaces/pms`
- [x] **SEC-03**: Verify EMS documents do NOT appear in PMS workspace list
- [x] **SEC-04**: Direct URL isolation attack: attempt direct navigation to EMS document URL `/documents/99166390-5f63-4677-84c6-c3237c5a5818` -> **Blocked with HTTP 404 Not Found**
- [x] **AUTH-09**: Sign out cleanly

---

## Live Action Stream & Observations

| Step | Timestamp (AWST) | Actor / URL | Action Performed | Browser Observation / Evidence | Status |
| :---: | :---: | :--- | :--- | :--- | :---: |
| 1 | 16:51 | System / `http://127.0.0.1:8011` | Checked live application availability | HTTP 200 on `/login`. Live MySQL session ready. | **PASS** |
| 2 | 16:52 | Chrome Subagent | Initialized Scratchpad & Checklist | [scratchpad_tu0o7ksd.md](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/73e318b3-9b34-46c9-8d35-44cda488f4a7/browser/scratchpad_tu0o7ksd.md) initialized. | **PASS** |
| 3 | 16:53 | `dms.admin@plpasig.edu.ph` | Typed credentials into `/login` | Email & Password populated, clicked "Sign in". CSRF validated. | **PASS** |
| 4 | 16:53 | Super Admin | Redirected to `/admin` | Rendered KPI cards, workspace summary cards (EMS, PMS, SMS, OAS), activity feed. | **PASS** |
| 5 | 16:54 | Super Admin | Navigated to `/admin/accounts` | Rendered accounts table with role tags, source assignments, and action menus. | **PASS** |
| 6 | 16:54 | Super Admin | Navigated to `/admin/health` | Health dashboard displayed: Storage OK, MySQL Connected, ClamAV active. | **PASS** |
| 7 | 16:54 | Super Admin | Navigated to `/admin/workspaces` | **CRITICAL BUG-006:** Rendered HTTP 500 Internal Server Error (`ErrorException: Undefined variable $section` in `layout.blade.php:68`). | **FAIL (BUG-006)** |
| 8 | 16:54 | Super Admin | Navigated to `/approvals` | Document approval queue rendered correctly. | **PASS** |
| 9 | 16:55 | Super Admin | Clicked logout | Session cleanly invalidated; redirected to `/login`. | **PASS** |
| 10 | 16:55 | `ems@plpasig.edu.ph` | Signed in as Scoped Admin (EMS) | Authenticated and navigated to `/workspaces/ems`. | **PASS** |
| 11 | 16:56 | EMS Admin | Opened document detail screen | Loaded `/documents/99166390-5f63-4677-84c6-c3237c5a5818` (`LIVE-CONFIDENTIAL-STAFF-EVAL.txt`). | **PASS** |
| 12 | 16:57 | EMS Admin | Inspected detail tabs | Overview, Versions (SHA-256 integrity), Sharing, and Activity tabs verified. | **PASS** |
| 13 | 16:58 | EMS Admin | Added Live Comment | Posted: *"Live manual QA verification via Google Chrome"*. Comment appeared instantly. | **PASS** |
| 14 | 16:58 | EMS Admin | Clicked logout | Clean session termination. | **PASS** |
| 15 | 16:59 | `employee@plpasig.edu.ph` | Signed in as EMS Staff | Authenticated and landed on `/workspaces/ems`. | **PASS** |
| 16 | 16:59 | EMS Staff | Security test: Attempted `/admin` | **HTTP 403 Forbidden** (`THIS ACTION IS UNAUTHORIZED.` banner). Staff cannot access Admin. | **PASS** |
| 17 | 17:00 | EMS Staff | Clicked logout | Clean session termination. | **PASS** |
| 18 | 17:00 | `pms.staff@plpasig.edu.ph` | Signed in as PMS Staff | Authenticated and landed on `/workspaces/pms`. EMS documents isolated. | **PASS** |
| 19 | 17:00 | PMS Staff | Cross-tenant test: Direct URL to EMS doc | **HTTP 404 Not Found**. PMS user cannot view or leak metadata for EMS documents. | **PASS** |
| 20 | 17:01 | PMS Staff | Clicked logout | Clean session termination. Complete run finished. | **PASS** |

---

## Confirmed Application Defect Register

The following application defects have been confirmed through manual Chrome testing and unit/integration test assertions:

### 1. BUG-006 (Severity: High / Critical) — `GET /admin/workspaces` HTTP 500
- **Location**: [WorkspaceRegistryController::index](file:///c:/Users/PLP/Documents/3RD%20YEAR/Integ/DMS/sdm-qa/app/Http/Controllers/WorkspaceRegistryController.php#L20) & [layout.blade.php:68](file:///c:/Users/PLP/Documents/3RD%20YEAR/Integ/DMS/sdm-qa/resources/views/layout.blade.php#L68)
- **Observed Behavior**: Visiting `/admin/workspaces` as Super Admin causes Laravel to throw `ErrorException: Undefined variable $section`.
- **Root Cause**: The controller returns `view('admin.workspaces-registry')` without passing `$section`. In `layout.blade.php`, `<body data-section="{{ $section }}">` expects `$section` to be set.
- **Evidence**: [bug_006_workspaces_500_1792745670692.png](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/73e318b3-9b34-46c9-8d35-44cda488f4a7/bug_006_workspaces_500_1792745670692.png)
- **Remediation**: Pass `'section' => 'workspaces', 'source' => null` from `WorkspaceRegistryController@index` or use `{{ $section ?? 'workspaces' }}` in `layout.blade.php`.

### 2. BUG-001 (Severity: Medium) — `/forgot-password` Unhandled 503
- **Location**: Password reset pipeline on unseeded email.
- **Observed Behavior**: Submitting an unknown or unseeded email to `/forgot-password` triggers an unhandled `503 Service Unavailable` instead of keeping the user on the screen with an informational message.

### 3. BUG-002 (Severity: Low) — `/invitations/accept/{token}` Unstyled 410 Gone
- **Location**: Invitation redemption route.
- **Observed Behavior**: Expired or malformed invitation tokens return a bare HTTP 410 error page without branding or a path back to login.

### 4. BUG-003 (Severity: Low) — Broken Link on Preview Rail
- **Location**: Navigation rail on synthetic preview.
- **Observed Behavior**: Link to `/admin/approvals` fails with "Preview page not found".

### 5. BUG-004 (Severity: Medium) — Collapsed Nav Rail Missing Accessible Labels
- **Location**: `layout.blade.php` sidebar icons.
- **Observed Behavior**: Collapsed navigation rail icons omit `aria-label` and `title`, causing accessibility tree failures in Chrome AX.

### 6. BUG-005 (Severity: Low) — Stale Pagination & Count on Empty Search Results
- **Location**: Document listing search filtering.
- **Observed Behavior**: When a search query matches zero documents, the table renders blank rows while retaining stale pagination counts (e.g., "Showing 1 to 12 of 20").

### 7. BUG-007 (Severity: High) — Modal ID Inconsistency on 409 Duplicate Conflict
- **Location**: Document upload modal & JavaScript event handler.
- **Observed Behavior**: Uploading a duplicate file returns HTTP 409 (`code: duplicate_file`), but the view template lacks the `#duplicate-warning-modal` element (which only exists in the staged frontend branch), leaving the user with an unstyled inline warning box.

### 8. BUG-008 (Severity: Low) — Flash Toast Auto-Dismiss without Hover Pause
- **Location**: `public/js/dms.js` toast notification system.
- **Observed Behavior**: Flash alerts dismiss after 4 seconds regardless of whether the user is actively hovering or attempting to read error details.

### 9. BUG-009 (Severity: Medium) — Missing Client-Side Upload Batch Size Guard
- **Location**: `resources/views/partials/upload.blade.php`.
- **Observed Behavior**: Multi-file dropzone allows queuing more than 50 files or over 500MB total without client-side prevention before transmission.

### 10. BUG-010 (Severity: Critical) — SQL Crash on Missing `folder_inheritance` Column
- **Location**: `app/Services/Access.php` & `WorkspaceController@index`.
- **Observed Behavior**: Navigating to `/workspaces/{source}` crashes with `SQLSTATE[42S22]: Column not found: 1054 Unknown column 's.folder_inheritance' in 'where clause'`. `Access::query()` attempts to filter `shares` by `s.folder_inheritance = 1`, but `folder_inheritance` was never migrated to the MySQL `shares` table schema.

---

## Phase 5: UI/UX Pro Max & Manual Chrome Audit Pass

A comprehensive manual audit document has been compiled in [docs/MANUAL-QA-UIUX-AUDIT.md](file:///c:/Users/PLP/Documents/3RD%20YEAR/Integ/DMS/sdm-qa/docs/MANUAL-QA-UIUX-AUDIT.md).
- **Session Video**: [`manual_qa_chrome_1792760613950.webp`](file:///C:/Users/PLP/.gemini/antigravity-ide/brain/c7fda3d4-a0aa-410f-b90f-aca26da17587/manual_qa_chrome_1792760613950.webp)
- **Responsive Viewport Evidence**: Zero horizontal scroll verified and screenshots captured across 375px, 768px, and 1280px in `docs/ui-evidence/responsive/` and `docs/ui-evidence/phase-1/screenshots/`.
- **UI/UX Critique**: Cataloged 12 "Garbage UI" anti-patterns (typography scale, contrast failure on badges, raw SHA-256 strings, empty state illustration absence, mobile touch target violations).
- **Missing Features**: Cataloged 12 enterprise features required by SDD v1.1 (bulk ZIP export, in-document full text search, PDF annotation canvas, TOTP MFA, notification drawer).
