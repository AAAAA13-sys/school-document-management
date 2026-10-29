# SDD Compliance, Gap Analysis & Backend Implementation Plan

**Target Source Document**: `docs/LATEST SDD.pdf` (Version 1.0 draft, 21 October 2026)  
**System**: PLP Document Management System (DMS) — Backend Layer  
**Author**: Backend Architecture Agent  
**Branch / Worktree**: `agent/backend-api`  
**Date**: October 23, 2026

---

## 1. Executive Summary & Verification of LATEST SDD.pdf

The latest Solution Design Document (`docs/LATEST SDD.pdf`, 24 pages) defines the target architecture, security controls, data models, integration boundaries, and business requirements for integrating the PLP Document Management System with four ERP systems:

- **EMS** (Employee Management System)
- **PMS** (Payroll Management System)
- **ES** (Enrollment System)
- **OAS** (Online Admission System)

This document provides a comprehensive traceability audit comparing `docs/LATEST SDD.pdf` against the active codebase, identifies all current gaps, specifies concrete architectural additions, and establishes the immediate worktree execution plan.

---

## 2. Requirements Traceability Matrix (SDD vs Codebase)

### 2.1 Functional Requirements (FR-01 to FR-13)

| ID        | Module             | SDD Requirement                                                                                                                                                                                                                      | Codebase Implementation                                                                                                           |    Status     | Gaps Identified                                                                                                    |
| :-------- | :----------------- | :----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | :-------------------------------------------------------------------------------------------------------------------------------- | :-----------: | :----------------------------------------------------------------------------------------------------------------- |
| **FR-01** | Documents          | Upload files as immutable versions after content validation and malware scan; keep every earlier version.                                                                                                                            | `DocumentService::upload`, `DocumentController::upload`, `DocumentVersion` model, `ClamAvScanner`. SHA-256 computed and verified. | **COMPLIANT** | None. Private storage isolation verified.                                                                          |
| **FR-02** | Workspace          | Nested folders, rename, move, star, trash and restore, safe previews, grid and list views.                                                                                                                                           | `Folder`, `FolderShare`, `Document`, `Star`, `DocumentMoves`, `WorkspaceController`.                                              | **COMPLIANT** | Frontend UI presents views; backend supports all operations.                                                       |
| **FR-03** | Sharing            | Document sharing grants Viewer, Commenter or Editor permissions, with expiry of at most 30 days and revocation. Editor can rename document and upload new versions (revision-checked). Editors cannot move, delete or reshare.       | `Share`, `ShareController`, `DocumentPolicy`, `2026_10_24_000007_cap_document_share_expiry.php`.                                  | **COMPLIANT** | Capped at 30 days in DB and policy.                                                                                |
| **FR-04** | Comments           | Comments on shared documents (up to 2,000 characters) and an activity sidebar.                                                                                                                                                       | `Comment`, `ShareController::comment`, `AuditEntry`. Max length 2,000 chars validated.                                            | **COMPLIANT** | None.                                                                                                              |
| **FR-05** | Accounts           | Invitation-only accounts: Super Admin invites Admins; Admin proposes Staff within its workspace and Super Admin approves; recipient sets own password; Staff cannot invite; only Super Admin grants Admin or Super Admin privileges. | `InvitationService`, `InvitationController`, `account_invitations` table.                                                         |  **PARTIAL**  | Missing Eloquent model `AccountInvitation`. `InvitationController` lacks JSON serialization for Frontend SPA.      |
| **FR-06** | History intake     | Accept history events from EMS, PMS, ES and OAS; store an immutable receipt; update latest snapshot only for newer revisions.                                                                                                        | `HistoryService`, `ApiController::history`, `history_receipts`, `source_records`.                                                 | **COMPLIANT** | Idempotent receipt matching with SHA-256 hash.                                                                     |
| **FR-07** | History read-back  | Cursor-based reads of history and events through the API; office and admin UI to inspect received versus current state.                                                                                                              | `ApiController::read`, `HistoryService`. Supports cursor pagination (`after_id`) and record diffing.                              | **COMPLIANT** | None.                                                                                                              |
| **FR-08** | Audit              | Record actor, action and document for sensitive actions.                                                                                                                                                                             | `AuditEntry`, `Access::audit`, `DocumentService::audit`. Audits auth, shares, uploads, packet reads, and approvals.               | **COMPLIANT** | None.                                                                                                              |
| **FR-09** | Scoped access      | Limit users and connectors to their school, campus, workspace and assigned memberships.                                                                                                                                              | `Access::scope`, `IntegrationAuth`, `DocumentPolicy`, `FolderPolicy`. Multi-tenant school/campus checks.                          | **COMPLIANT** | None.                                                                                                              |
| **FR-10** | Workspace registry | Database-driven registry of ERP and internal workspaces, each with a stable code, editable name and lifecycle state; permission-filtered selector; adding or renaming needs no code change.                                          | `Workspace`, `WorkspaceMembership`, `WorkspaceRegistry`, `WorkspaceRegistryController`.                                           |  **PARTIAL**  | `WorkspaceRegistryController` lacks JSON serialization when requested via AJAX (`expectsJson()`).                  |
| **FR-11** | Memos              | Staff memo issuance with type, reference, issuer and audience; released version is pinned for circulation; later versions stay unreleased until approved.                                                                            | `MemoService`, `memo_publications` table, `ApprovalController::publish`.                                                          |  **PARTIAL**  | Missing Eloquent model `MemoPublication`. Missing direct relationship on `Document`.                               |
| **FR-12** | Applicant packet   | OAS releases specific verified document versions of an admitted applicant; ES retrieves only Clean versions through explicit grant; no medical files through ordinary connector.                                                     | `ApplicantPacketService`, `ApplicantPacketController`, `applicant_packets`, `packet_receipts`.                                    |  **PARTIAL**  | Missing Eloquent models `ApplicantPacket` and `PacketReceipt`. Endpoints need inclusion in `API_SPECS.md`.         |
| **FR-13** | Approvals          | Sensitive changes (sharing, classification, trash/restore, publication, account changes): Staff requests go to scoped Admin; Admin to Super Admin; approve/reject with reason; no self-approval; atomic re-check and apply once.     | `SensitiveChangeService`, `AccountChangeService`, `ApprovalController`, `AccountChangeController`, `sensitive_changes` table.     |  **PARTIAL**  | Missing Eloquent models `SensitiveChange` and `AccountChange`. Controllers lack JSON responses for Frontend Agent. |

---

### 2.2 Non-Functional Requirements & Security (NFR-01 to NFR-09)

| ID         | Category      | SDD Requirement                                                                                                                                 | Codebase Implementation                                                                                      |    Status     |
| :--------- | :------------ | :---------------------------------------------------------------------------------------------------------------------------------------------- | :----------------------------------------------------------------------------------------------------------- | :-----------: |
| **NFR-01** | Security      | Session sign-in with active-account check, CSRF protection, throttling; connector tokens hashed and bound to source, school, campus; HTTPS/TLS. | `auth`, `active`, `throttle:signin`, `throttle:connector`, SHA-256 connector tokens.                         | **COMPLIANT** |
| **NFR-02** | Integrity     | SHA-256 checksum recorded for every version and re-verified on access; versions are immutable.                                                  | `DocumentService::upload`, `DocumentController::download`, `hash_file('sha256', ...)`.                       | **COMPLIANT** |
| **NFR-03** | Idempotency   | Retried request with same key returns original receipt (200). Changed request returns 409. Older revision stored (applied=false).               | `HistoryService::record`, `ApplicantPacketService::receive`. CAS revision check.                             | **COMPLIANT** |
| **NFR-04** | Limits        | API throttle 120 rpm; file up to 10 MB; history payload <= 256 KiB; batch upload <= 50 files.                                                   | Validated in `BatchUploadController`, `ApiController`, and `DocumentController`.                             | **COMPLIANT** |
| **NFR-05** | Availability  | 99.5% monthly availability; authenticated health checks.                                                                                        | `OperationsHealthController` at `/admin/health`.                                                             | **COMPLIANT** |
| **NFR-06** | Recovery      | Daily encrypted backup, coordinated restore of MySQL and private files.                                                                         | `app/Console/Commands/CoordinatedBackup.php`, `VerifyRestoreReadiness.php`.                                  | **COMPLIANT** |
| **NFR-07** | Privacy       | Compliant with Philippine Data Privacy Act (RA 10173); minors' data restricted.                                                                 | Data classification (`Internal`, `Confidential`, `Restricted`), medical file exclusion in packet service.    | **COMPLIANT** |
| **NFR-08** | Auditability  | Audit history kept for 3 years; legal hold suspends disposal.                                                                                   | `audit_entries`, `audit_legal_holds`, `AuditLegalHold.php` artisan command. Missing web API for legal holds. |  **PARTIAL**  |
| **NFR-09** | Accessibility | WCAG 2.2 Level AA target for staff-facing pages, keyboard navigation, skip link.                                                                | Frontend responsibility (adhered to in Blade views).                                                         | **COMPLIANT** |

---

### 2.3 Integration Interfaces (INT-01 to INT-07)

1. **INT-01 (EMS $\to$ DMS)**: REST push, idempotent, history events + documents (`/api/v1/history`, `/api/v1/documents`). _(Compliant)_
2. **INT-02 (PMS $\to$ DMS)**: REST push, idempotent, history events + documents (`/api/v1/history`, `/api/v1/documents`). _(Compliant)_
3. **INT-03 (ES $\to$ DMS)**: REST push, idempotent, history events + documents (`/api/v1/history`, `/api/v1/documents`). _(Compliant)_
4. **INT-04 (OAS $\to$ DMS)**: REST push, applicant packet release (`/api/v1/applicant-packets`). _(Compliant)_
5. **INT-05 (ERP Read-back)**: DMS $\to$ ERP cursor-based reads (`/api/v1/{history|events|documents|files}`). _(Compliant)_
6. **INT-06 (Malware Scanner)**: ClamAV command and retry artisan pipeline. _(Compliant)_
7. **INT-07 (Applicant Packet Read)**: DMS $\to$ ES explicit read-only packet retrieval (`/api/v1/applicant-packets/{packet}`). _(Compliant)_

---

### 2.4 Architecture Decisions Log (AD-01 to AD-10)

- **AD-01**: Local ClamAV engine with `dms:status` / scan-retry.
- **AD-02**: Unified Laravel monolith with 4 logical tiers.
- **AD-03**: Single MySQL database + private filesystem storage.
- **AD-04**: Scoped bearer tokens, idempotent receipts, revision CAS.
- **AD-05**: File scan is a security gate, not business verification.
- **AD-06**: Students are not DMS users.
- **AD-07**: Hosting-neutral architecture.
- **AD-08**: Database-driven workspace registry (`workspaces` table).
- **AD-09**: Cross-system access only through explicit release grants (`applicant_packets`).
- **AD-10**: Invitation-only accounts with dual-level approvals (`account_invitations`).

---

## 3. Detailed Gap Analysis & Technical Deficiencies

To achieve complete system maturity and provide full support for the Frontend Agent and QA Agent, the following backend gaps must be resolved:

### Gap 1: Missing Eloquent Models

The database schemas exist, but Eloquent models have not yet been defined for:

1. `App\Models\SensitiveChange` (`sensitive_changes` table)
2. `App\Models\AccountInvitation` (`account_invitations` table)
3. `App\Models\AccountChange` (`account_changes` table)
4. `App\Models\ApplicantPacket` (`applicant_packets` table)
5. `App\Models\PacketReceipt` (`packet_receipts` table)
6. `App\Models\MemoPublication` (`memo_publications` table)
7. `App\Models\AuditLegalHold` (`audit_legal_holds` table)

### Gap 2: Missing Eloquent Relationships

The existing `User` and `Document` models lack Eloquent relationships to connect to these entities:

- `User`: `sensitiveChangesRequested()`, `sensitiveChangesReviewed()`, `accountInvitationsRequested()`, `accountInvitationsReviewed()`, `accountChangesRequested()`, `accountChangesReviewed()`.
- `Document`: `sensitiveChanges()`, `memoPublication()`.

### Gap 3: Missing Dual JSON API Responses in Controllers

When the Frontend Agent initiates AJAX/Fetch calls with `Accept: application/json`, the following controllers currently return HTML Blade views or redirects (`back()->with(...)`):

- `ApprovalController::index` (returns HTML view; must return JSON list of pending/decided approvals)
- `InvitationController::index`, `store`, `review`, `send`, `revoke` (must return structured JSON with invitation data and token status)
- `AccountChangeController::index`, `decide` (must return JSON list and decision receipt)
- `WorkspaceRegistryController::index`, `store`, `update`, `membership` (must return JSON list of workspaces, users, memberships, and operation receipts)
- `AdminController::index`, `account`, `connector`, `revoke` (must return JSON summaries, account changes, and generated connector tokens)

### Gap 4: Missing Web/API Route for Legal Holds

The `AuditLegalHold` artisan command exists, but authorized Super Admins need web/API endpoints to list active legal holds and record/release holds via the admin console:

- `GET /admin/legal-holds`
- `POST /admin/legal-holds`
- `POST /admin/legal-holds/{hold}/release`

### Gap 5: Incomplete API Contract in API_SPECS.md

`API_SPECS.md` documents Document and Workspace endpoints, but does not yet specify:

- Sensitive Changes / Approval Queue endpoints
- Account Invitations & Account Change workflows
- Workspace Registry management endpoints
- Staff Memo publication and circulation endpoints
- OAS-to-ES Applicant Packet endpoints (`/api/v1/applicant-packets`)
- Audit Legal Hold endpoints

---

## 4. Implementation Plan for Worktree (`agent/backend-api`)

### Phase 1: Eloquent Models & Relationships

1. Create all 7 missing Eloquent models with strict type hinting, fillable attributes, and casts.
2. Update `User.php` and `Document.php` with reverse relationships.
3. Verify autoloading via `composer dump-autoload`.

### Phase 2: Dual JSON API Handling in Controllers

1. Update `ApprovalController` to return JSON on `index`.
2. Update `InvitationController` to return JSON across all actions when `expectsJson()`.
3. Update `AccountChangeController` to return JSON on `index` and `decide`.
4. Update `WorkspaceRegistryController` to return JSON on all actions.
5. Update `AdminController` to return structured JSON on `index`, `account`, `connector`, `revoke`.
6. Add legal hold endpoints in `AdminController` or dedicated controller and register routes in `routes/web.php`.

### Phase 3: Contract Update in API_SPECS.md

Expand `API_SPECS.md` with complete JSON schemas, payload examples, and error responses for:

- Approvals Queue
- Account Invitations & Requests
- Workspace Registry Admin
- Staff Memos
- Applicant Packets (REST API)
- Legal Holds

### Phase 4: Test Validation & Verification

1. Create comprehensive feature tests in `tests/Feature/SensitiveChangeAndApprovalApiTest.php` covering the new models and JSON endpoints.
2. Run full test suite via `vendor/bin/phpunit` to confirm 100% pass rate without regressions.
3. Commit clean working state to `agent/backend-api`.
