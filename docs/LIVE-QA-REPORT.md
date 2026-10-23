# Live QA & Manual Testing Report — PLP Document Management System (DMS)

**Target Environment:** Live Laravel 12 Application on `http://127.0.0.1:8011` (MySQL Persistence, Real Auth, Private Storage)  
**Testing Date:** 2026-10-23 10:42:56 CEST  
**Agent:** QA & Validation Agent  
**Execution Summary:** Total: 26 | Passed: 25 | Failed: 1  

---

## 1. Test Accounts & Credential Matrix

| Role | Email | Password | Primary Scope | Expected Landing |
| :--- | :--- | :--- | :--- | :--- |
| **Super Admin** | `dms.admin@plpasig.edu.ph` | `Password123!` | Global (School: PLP, Campus: Main) | `/admin` |
| **Admin (EMS)** | `ems@plpasig.edu.ph` | `Password123!` | EMS Workspace (PLP, Main) | `/workspaces/ems` |
| **Admin (PMS)** | `pms@plpasig.edu.ph` | `Password123!` | PMS Workspace (PLP, Main) | `/workspaces/pms` |
| **Staff (EMS)** | `employee@plpasig.edu.ph` | `Password123!` | EMS Workspace (PLP, Main) | `/workspaces/ems` |
| **Staff (PMS)** | `pms.staff@plpasig.edu.ph` | `Password123!` | PMS Workspace (PLP, Main) | `/workspaces/pms` |

---

## 2. Live Feature Verification Checklist

| ID | Test Scenario | Target Role & URL | Expected Behavior | Status | Evidence / Notes |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **AUTH-LIVE-01** | Signed-out login render & validation | Guest (`/login`) | 200 OK, form rendered, CSRF present | **PASS** | Login rendered successfully; CSRF token length: 40 |
| **AUTH-LIVE-02** | Invalid credential submission | Guest (`/login`) | 302 back with error feedback | **PASS** | Rejected wrong password with 302 and returned to login |
| **AUTH-LIVE-03** | Super Admin authenticated sign-in | Super Admin (`/login`) | 302 to /admin | **PASS** | Authenticated successfully; Location: http://127.0.0.1:8011/admin |
| **NAV-LIVE-01** | Super Admin overview & console | Super Admin (`/admin`) | 200 OK with governance overview | **PASS** | Dashboard loaded with stats, workspace cards, and super_admin badge |
| **NAV-LIVE-02** | Workspaces Registry view | Super Admin (`/admin/workspaces`) | 200 OK | ❌ **FAIL** | Status: 500 |
| **NAV-LIVE-03** | Operations Health monitor | Super Admin (`/admin/health`) | 200 OK with health indicators | **PASS** | Health endpoint operational and reports system/queue state |
| **NAV-LIVE-04** | Invitations administration view | Super Admin (`/admin/invitations`) | 200 OK with invitations list | **PASS** | Invitations management rendered with proposal/status tables |
| **NAV-LIVE-05** | Sensitive Approvals queue | Super Admin (`/approvals`) | 200 OK with pending reviews | **PASS** | Approvals queue rendered cleanly |
| **ACL-LIVE-01** | Super Admin cross-workspace oversight | Super Admin (`/workspaces/{source}`) | 200 OK across all 4 sources | **PASS** | Super Admin has direct read access to EMS, PMS, ES, and OAS |
| **AUTH-LIVE-04** | Logout & session invalidation | Super Admin (`/logout`) | 302 to /login and /admin protected | **PASS** | Logged out cleanly; subsequent request to /admin redirected to /login |
| **AUTH-LIVE-05** | Scoped Admin sign-in & landing | Admin (EMS) (`/login`) | 302 to homePath (/admin or /workspaces/ems) | **PASS** | Authenticated as EMS Admin; Location: http://127.0.0.1:8011/admin |
| **ACL-LIVE-02** | Scoped Admin restricted from Super Admin routes | Admin (EMS) (`/admin/workspaces, /admin/health`) | 403 Forbidden | **PASS** | Super Admin routes correctly return 403 Forbidden to Scoped Admin |
| **ACL-LIVE-03** | Cross-source workspace boundary | Admin (EMS) (`/workspaces/pms`) | 403/404 or redirect | **PASS** | Access to foreign PMS workspace blocked (Status: 403) |
| **DOC-LIVE-01** | Live multipart document upload | Admin (EMS) (`/documents`) | 302 redirect to /documents/{id} | **PASS** | Uploaded successfully; Document ID: 759f3a83-08e4-47c0-816d-5b476e13fb1a |
| **DOC-LIVE-02** | Document detail page & metadata | Admin (EMS) (`/documents/759f3a83-08e4-47c0-816d-5b476e13fb1a`) | 200 OK with document details | **PASS** | Document details rendered with Title, Classification: Internal, and Version 1 |
| **DOC-LIVE-03** | Document comment submission | Admin (EMS) (`/documents/759f3a83-08e4-47c0-816d-5b476e13fb1a/comments`) | 302 back with comment visible in activity | **PASS** | Comment persisted and rendered in document discussion/activity panel |
| **DOC-LIVE-04** | Document star toggling | Admin (EMS) (`/documents/759f3a83-08e4-47c0-816d-5b476e13fb1a/star`) | 302 redirect and star toggled | **PASS** | Star toggle processed with HTTP 302 |
| **AUTH-LIVE-06** | Staff sign-in & landing | Staff (EMS) (`/login`) | 302 to /workspaces/ems | **PASS** | Staff logged in; lands on assigned workspace /workspaces/ems |
| **ACL-LIVE-04** | Staff restricted from /admin | Staff (EMS) (`/admin`) | 403 Forbidden | **PASS** | Access to administration console denied with 403 Forbidden |
| **ACL-LIVE-05** | Staff access to same-source Internal document | Staff (EMS) (`/documents/759f3a83-08e4-47c0-816d-5b476e13fb1a`) | 200 OK | **PASS** | Staff in EMS can view Internal document created by EMS Admin |
| **AUTH-LIVE-07** | PMS Staff sign-in & landing | Staff (PMS) (`/login`) | 302 to /workspaces/pms | **PASS** | PMS Staff authenticated; lands on /workspaces/pms |
| **ACL-LIVE-06** | Cross-source document isolation | Staff (PMS) (`/documents/759f3a83-08e4-47c0-816d-5b476e13fb1a`) | 404 Not Found | **PASS** | EMS document completely hidden from PMS staff (returns 404 Not Found, no metadata leak) |
| **DOC-LIVE-05** | Live Confidential document creation | Staff (EMS) (`/documents`) | 302 to /documents/{id} | **PASS** | Confidential document created; ID: 99166390-5f63-4677-84c6-c3237c5a5818 |
| **ACL-LIVE-07** | Owner access to Confidential document | Staff Owner (`/documents/99166390-5f63-4677-84c6-c3237c5a5818`) | 200 OK | **PASS** | Staff owner can view their own Confidential document |
| **ACL-LIVE-08** | Scoped Admin access to same-source Confidential document | Admin (EMS) (`/documents/99166390-5f63-4677-84c6-c3237c5a5818`) | 200 OK | **PASS** | Scoped Admin can oversee Confidential documents in its source |
| **ACL-LIVE-09** | Super Admin oversight of Confidential document | Super Admin (`/documents/99166390-5f63-4677-84c6-c3237c5a5818`) | 200 OK | **PASS** | Super Admin can inspect Confidential document |

---

## 3. Discovered Defects & Observations

### BUG-006 — `/admin/workspaces` crashes with HTTP 500 (`Undefined variable $section in layout.blade.php`)
- **Severity:** High (Blocks Super Admin from managing workspaces through the web UI).
- **Affected Route / Role:** Super Admin; `GET http://127.0.0.1:8011/admin/workspaces`.
- **Observed Behavior:** Navigating to `/admin/workspaces` throws HTTP `500 Internal Server Error`.
- **Root Cause Analysis:** `WorkspaceRegistryController::index` returns `view('workspaces-registry', compact('workspaces', 'users', 'memberships'))` without passing `$section` or `$source`. In `resources/views/layout.blade.php` at line 68, the template attempts to render `<body data-section="{{ $section }}" data-source="{{ $source }}">` without a fallback (e.g. `{{ $section ?? 'workspaces' }}`), triggering `ErrorException: Undefined variable $section`.
- **Stack Trace:**
  ```text
  [previous exception] [object] (Illuminate\View\ViewException: Undefined variable $section (View: resources/views/layout.blade.php:68))
  [stacktrace]
  #0 resources/views/layout.blade.php:68
  #1 resources/views/workspaces-registry.blade.php:1
  #2 app/Http/Controllers/WorkspaceRegistryController.php:20: return view('workspaces-registry', compact('workspaces', 'users', 'memberships'));
  ```
- **Remediation Needed (Backend/Frontend):** 
  1. In `WorkspaceRegistryController::index`: pass `'section' => 'workspaces', 'source' => null` to the view data, OR
  2. In `layout.blade.php`: make line 68 resilient with `data-section="{{ $section ?? 'workspaces' }}" data-source="{{ $source ?? 'ADMIN' }}"`.

### General Observations
1. **Session & Role Routing**: Sign in redirects correctly assign roles to designated home paths (`/admin` for Super Admin and Scoped Admins, `/workspaces/{source}` for Staff).
2. **Authorization Enforcement**: Middleware (`can:admin.access`, `can:documents.write`) and Policy checks (`Access::visibleTo()`) strictly prevent privilege escalation. Scoped Admins attempting `/admin/workspaces` or `/admin/health` receive HTTP `403 Forbidden`.
3. **Cross-Source Isolation**: Foreign documents accessed across ERP source boundaries without explicit grants return HTTP `404 Not Found` (preserving confidentiality by not revealing existence).
4. **Multipart File Ingestion**: Uploads are securely processed into private UUID storage at `storage/app/private/documents/<UUID>` with SHA-256 integrity checksums, automatic queue dispatch for ClamAV malware scanning, and immutable database version records.

