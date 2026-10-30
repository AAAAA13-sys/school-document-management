# Latest SDD implementation authority - 23 October 2026

The user authorized implementing `docs/LATEST SDD.pdf` (v1.0 draft, 21 October 2026), including Samuel's account/access work. Yuri's CI, integration coordination, reporting, task-board and presentation work is deferred. This section supersedes conflicting historical rules below, especially read-only Super Admin, fixed four-workspace selectors, direct account creation and new folder sharing.

- Super Admin may manage DMS-held documents within its school/campus. Classification visibility remains centralized in `Access::visibleTo()`; Restricted sharing remains reserved to Super Admin.
- Registered workspaces and memberships drive access and selectors. ERP sources EMS/PMS/ES/OAS remain initial integrations; internal workspaces are repositories, not connectors. An unassigned legacy Admin fails closed.
- Account creation uses individual, expiring, single-use invitations; recipients choose passwords. Admin Staff invitations and account changes require Super Admin approval. Sensitive document changes by Staff require a scoped Admin; Admin changes require Super Admin. Pending changes leave live state unchanged; decisions require reasons, fresh authority, revisions and one application.
- Memo publication pins an approved Clean version. Later uploads remain drafts for circulation. OAS packet releases authorize ES to retrieve only exact Clean versions through packet endpoints; ordinary connectors keep source isolation. Medical inclusion/access still requires an agreed contract.
- New folder sharing is outside the latest SDD. Historical folder grants remain compatible; document grants expire within 30 days.
- The latest SDD specifies 99.5% monthly availability, RPO 24h/RTO 8h, daily encrypted coordinated backups, quarterly recovery drills, three-year minimum audit retention with legal holds, and WCAG 2.2 AA. Target-host measurements, actual drills, DPIA, mail delivery, partner adapters, hosting/data residency and client sign-offs require external evidence; code does not certify them.

Read the historical guide below for architecture, file integrity, API idempotency and other unchanged requirements. For changed workflows, inspect `WorkspaceRegistry`, `InvitationService`, `AccountChangeService`, `SensitiveChangeService`, `MemoService` and `ApplicantPacketService` and their tests.

---

AGENT.md — Document Management System (DMS)
Project: DMS for Pamantasan ng Lungsod ng Pasig (PLP) School ERP
Source of truth: DMS-SDD.pdf (Software Design Document v0.1, 2026-10-13)
Stack: Laravel 13, PHP 8.3+, Pest PHP / PHPUnit, MySQL (InnoDB), Blade + Bootstrap 5.3 + jQuery 3.7.1 / jQuery UI 1.14, ClamAV
Audience: AI coding agents and human engineers working on this repository

1. Mission
   Build and maintain the Document Management System (DMS) — a single Laravel 13 application that serves as the centralized repository for documents, versions, sharing, audit trail, and received transaction history for four independent ERP systems (EMS, PMS, ES, OAS).

The DMS is one application, one MySQL database, one private file store. No microservices. No managed cloud DB/queue/search/object storage.

2. Non-Negotiable Constraints

# Constraint Source

C1 Single Laravel 13 app on PHP 8.3+, four logical tiers (Presentation, Application, Integration, Persistence) Section 7.1, Section 7.2
C2 One MySQL (InnoDB) database + private folder storage. No managed cloud DB, queue, search, or object storage. Section 7.1, Section 11.2, AD-03
C3 Only public/ is web-served. All version files live in storage/app/private/documents/<version UUID>. Section 7.1, Section 10.3
C4 All files validated (actual content), max 10 MB, SHA-256 checksummed, ClamAV-scanned before being served. FR-01, NFR-02, Section 10.3
C5 File versions are immutable. Earlier versions are never overwritten or deleted (except soft trash of the parent document). FR-01, NFR-02, Section 3.2
C6 All ERP integration goes through /api/v1 only. No cross-database access between ERP systems. Section 7.1, Section 9, AD-04
C7 Idempotency: identical replay gives 200 with original receipt; changed hash or stale revision gives 409; older revision is stored with applied=false, snapshot unchanged. NFR-03, Section 9
C8 Every sensitive action (upload, download, modify, delete, access change) is written to the audit log. OBJ-05, FR-08
C9 Students are never DMS users. Section 2, Section 3.4, AD-06
C10 Malware scanning uses local ClamAV via an Artisan command + scan-retry command. No SaaS scanning APIs. Section 7.4, AD-01
C11 Access is scoped by school, campus, and (for office roles) source system. FR-09, NFR-01
C12 Low running cost is a design objective (OBJ-04). Do not introduce paid infrastructure without an explicit architecture decision. OBJ-04, AD-01, AD-03 3. Architecture at a Glance
text
Browser users --> [1 Presentation] ---+
+--> [2 Application: Laravel 13 / PHP 8.3+]
ERP connectors --> [3 Integration /api/v1] --+ |
+--> [4 DMS MySQL (InnoDB)]
+--> [4 Private version files]
+--> ClamAV scanner
Tier rules:

Tier 2 (Application) is the only writer to MySQL and private files.

Tiers 1 and 3 feed Tier 2; they never touch persistence directly.

Tier 1 = Blade views, session auth, CSRF, workspace UI.

Tier 3 = /api/v1, bearer token + scope check, 120 req/min throttle, idempotent receipts, revision CAS.

Key components (Tier 2):

DriveController — folders, trash, previews

ShareController — grants, comments

AccountController — roles, activation

DocumentController + DocumentService — validate, version, scan, audit

HistoryController — receipts, revision CAS, snapshots

4. Domain Model (Core Entities)
   Entity Key attributes System of record
   User account id, email, password hash, role, active, school_id, campus DMS
   Folder UUID, parent_id, owner, name, source, school, campus, revision DMS
   Document UUID, owner, folder, title, subject, reference, source, category, classification, revision, trashed_at DMS
   Document version UUID, document_id, number, filename, MIME, size, checksum, storage_key, scan_status DMS (bytes private)
   Share / comment / star document, version, recipient, permission, expiry, revocation DMS
   Source record snapshot scoped record key, source, school/campus, record type+ID, revision, deleted, payload Source ERP (DMS keeps latest copy)
   Received history receipt cursor ID, record key, event ID, revision, request hash, operation, payload, applied DMS (immutable)
   Audit entry / integration event school/campus, actor, action, document, event type, revision DMS
   Connector identity / request receipt source, school/campus, token hash; request key, request hash, response receipt DMS
   Data classification (proposed — pending client sign-off):

Restricted — password hashes, token hashes, payroll and HR documents

Confidential — enrollment/admission documents, received history payloads, audit entries

Internal — general office working files

5. Roles & Permissions
   | Role        | Own/source documents                                                           | Shared documents                                               | Received history | Accounts and audit                                                      |
   | ----------- | ------------------------------------------------------------------------------ | -------------------------------------------------------------- | ---------------- | ----------------------------------------------------------------------- |
   | Super Admin | Read every source; no document/folder writes                                   | Read via oversight                                             | All sources      | Manage all accounts and connectors; full audit                          |
   | Admin       | Manage documents/folders in the assigned source                                | Read named grants; no cross-source management                  | Assigned source  | Manage Staff in the assigned source; source-scoped audit; no connectors |
   | Staff       | Upload; read all documents in the assigned source; edit/delete owned documents | Viewer/Commenter/Editor grants; Editor can edit but not delete | No               | No                                                                      |

Share permissions: Viewer, Commenter, Editor.

Editor can rename the document and upload new versions (revision-checked).

Editor cannot move, delete, or reshare. (FR-03)

Comments: max 2,000 characters, with activity sidebar. (FR-04)

Account rules:

Super Admin manages every account; Admin creates/edits/suspends Staff only in its assigned source and cannot manage Admins or Super Admins. (FR-05)

No self-demotion or self-deactivation. (FR-05, Section 10.1)

New accounts inherit the administrator's school/campus. (Section 10.1)

6. API Contract Rules (/api/v1)
   Auth: Bearer token, stored hashed, bound to one source + school + campus. (Section 10.1, NFR-01)

Throttle: 120 requests/minute per connector. (NFR-04)

Limits: file up to 10 MB; history payload up to 256 KiB; folder upload up to 50 files per batch. (NFR-04)

Idempotency:

New event gives 201 with history_id, applied, duplicate.

Identical replay gives 200 with original receipt.

Same event, different hash gives 409.

Another event at same record revision gives 409.

Older revision is stored with applied=false; snapshot unchanged.

Delete becomes a tombstone; earlier payloads kept.

Under contention, bounded retries then 409. (NFR-03, Section 9)

Read-back: cursor-based GET for history, events, documents, files. (INT-05)

No education data standard is used today. Contract is a JSON format carrying event ID, record identity, operation, and revision. (Section 9)

Error handling: source retries same event with backoff and jitter; persistent conflicts need operator investigation (alerting TBD).

7. Security Rules
   Session sign-in with active-account check, CSRF protection, sign-in throttling. (NFR-01)

HTTPS only; only public/ served. (Section 10.3)

File pipeline: actual-content validation, 10 MB limit, private storage, UUID path, SHA-256 on access, malware scan, block if not clean. (FR-01, NFR-02, Section 10.3)

Application: CSRF, sign-in throttle, API throttle, hashed tokens, school/campus checks on every shared operation. (Section 10.3)

Integrity: immutable versions and received history, canonical request hashes, unique constraints. (Section 10.3)

Audit: actor, action, document. (FR-08, Section 10.3)

Not yet decided: encryption at rest, central monitoring, penetration testing, SSO, MFA. (Section 10.1, Section 10.3)

Compliance: Philippine Data Privacy Act of 2012 (RA 10173) [to confirm]; DPIA before go-live; children's personal data in ES/OAS payloads restricted to office/admin roles. (Section 8.3, Section 10.4)

8. Coding Standards & Conventions
   Framework: Laravel 13 conventions; Pest PHP / PHPUnit testing; PHP 8.3+ typed properties, constructor promotion, enums where useful.

Frontend: Blade + Bootstrap 5.3 + jQuery 3.7.1 + jQuery UI 1.14. No SPA framework.

DB: MySQL InnoDB; unique constraints and CAS for idempotency and revision safety.

Files: never store in public/; always storage/app/private/documents/<version UUID>.

Naming: controllers *Controller, service layer DocumentService, events *Event, jobs queued via Artisan (no external queue).

Migrations: one concern per migration; never alter applied migrations.

Tests: PHPUnit/Pest; API contract tests, concurrency tests, security tests, backup/restore tests (see Section 12.3).

Audit: every sensitive action goes through the audit pipeline — no silent writes.

Accessibility: support keyboard navigation, readable text, accessible navigation. Formal WCAG 2.2 AA compliance is subject to client approval. (NFR-09)

9. Out of Scope (Do Not Build)
   Student accounts or student access. (AD-06)

External / anonymous / public sharing links. (Section 3.4)

Folder-level sharing. (Section 3.4)

In-browser editing of document contents. (Section 3.4, FR-03)

File preview for formats outside the agreed list (list TBD). (Section 3.4)

Business approval/verification of admissions, enrollment, employment, payroll. (AD-05)

Direct management/modification of source records in ERP systems. (Section 3.4)

Source-side adapters and outbox (owned by each ERP team). (Section 3.4, Section 12.1)

Modification of business logic and databases within ERP systems. (Section 3.4)

SSO and MFA (TBD with Enterprise Architect). (Section 10.1)

Encryption at rest, central monitoring, pen testing (TBD). (Section 10.3)

10. Open Items / TBD (Flag, Do Not Invent)
    ID Open item Owner
    O1 Hosting model (local/on-prem vs cloud) Enterprise Architect (R-01, AD-07)
    O2 Volumes and peak loads Client
    O3 File preview formats list Client
    O4 RPO / RTO To be agreed
    O5 Data residency region To be agreed
    O6 Audit retention period To be agreed
    O7 Retention schedule per entity Client
    O8 SSO / MFA decision Enterprise Architect
    O9 Encryption at rest, monitoring, pen testing To be decided
    O10 Backup/restore test frequency To be agreed
    O11 Support tiers and SLAs To be defined
    O12 Alert recipient for persistent conflicts To be defined
    O13 CI/CD and environments To be defined
    O14 DPIA ID and completion before go-live Client / DPO
    O15 Accessibility target confirmation Client
    O16 Data classification sign-off Client
    Rule: When a requirement references a TBD, implement the design as hosting-neutral and configuration-driven where possible, and leave a clearly marked placeholder — do not silently choose.

11. Architecture Decisions Log (Binding)
    ID Decision Rationale
    AD-01 Local ClamAV engine via scan-retry Artisan command Low cost (OBJ-04); on-prem or hosted; no vendor recurring cost
    AD-02 One Laravel deployment, custom-built, four logical tiers Small footprint; shared DocumentService
    AD-03 One MySQL database + private folders Lowest running cost; one backup set
    AD-04 Scoped bearer tokens, idempotent receipts, revision CAS No writes to other systems' DBs; safe retries
    AD-05 File scan is a security control, not business approval Approval belongs to school users / originating systems
    AD-06 Students are not DMS users Outside agreed scope
    AD-07 Hosting model Open: pending Enterprise Architect
    Any change to these decisions requires a new AD entry and reviewer sign-off.

12. Definition of Done (per feature)
    Traces to a requirement ID (FR-, NFR-, OBJ-*).

Audited if it touches sensitive actions.

Scoped by school/campus/source where applicable.

Idempotent and revision-safe if it accepts ERP input.

Files go through validate, checksum, scan, private store.

Tests: unit/integration + acceptance (per Section 12.3 exit criteria).

No new paid infrastructure introduced.

TBDs flagged, not invented.

Reviewer sign-off per the Reviewer table (SDD pages 1–2).
Phase 3 authorization (2026-10-23): Super Admin has `source = null`, manages all accounts and connectors, can read every document/folder in all four ERP sources, and sees the full audit log; document and folder management is read-only. Admin has one ERP source, manages Staff accounts only in that source (never Admins or Super Admins), manages documents and folders in that source, and sees source-scoped audit entries. Staff can upload and read all documents in its own source, edit/delete only its own documents or those with an explicit Editor grant, and cannot manage accounts, folders, sharing or audit. DMS is the application, not a document source or fifth ERP source; there is no `/my-workspace` personal source.

Authorization is enforced by Laravel policies/Gates and route middleware, not by hiding controls. In-scope forbidden actions return 403; IDs outside the actor's school/campus/source scope return 404. Inactive HTML sessions redirect to `/login`; JSON/API/download requests return 403.

Named document shares and folder grants may cross ERP source boundaries as a deliberate, documented exception. School/campus scope and recipient validity still apply; source isolation still applies to ordinary workspace/repository access.

Legacy role handling: `employee`, `teacher` and `hr` map to `staff` + `EMS`; `payroll` maps to `staff` + `PMS`. Legacy `registrar` and legacy `admin` are not auto-migrated and remain follow-ups.

Phase 4 classification access (2026-10-23): the existing `documents.classification` field is now enforced with values `Internal`, `Confidential` and `Restricted`. Internal is readable by active users in the same source plus Super Admin. Confidential is readable by the owner, the Admin of that source, Super Admin, and explicit document recipients; Staff need ownership or an explicit document grant. Restricted is readable only by the owner, Super Admin, and explicit document recipients. Folder grants widen Internal access only. Only Super Admin can create or revoke a Restricted document share; Super Admin remains read-only for every other document write. Classification changes require management authority plus visibility of the target level: owners may choose any level, while non-owner Admins may choose Internal/Confidential only.

`Access::visibleTo()` is the single reusable visibility scope, and `DocumentPolicy` uses the same rules. Hidden documents return 404 and are excluded from workspace/repository listings, search, counts and stats, folder totals, downloads/previews, version history, duplicate warnings, API read-back/events/files, and document audit entries. API connectors may read Internal and Confidential documents in their source but never Restricted documents. The database default for unspecified classification is now Internal; existing explicit classifications are preserved.

Tests: `tests/Feature/Phase4ClassificationTest.php` covers the role/level matrix, classification changes, folder grants/totals, search/counts/downloads/duplicates, API read-back and the database default.
