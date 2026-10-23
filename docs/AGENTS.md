AGENT.md — Document Management System (DMS)
Project: DMS for Pamantasan ng Lungsod ng Pasig (PLP) School ERP
Source of truth: DMS-SDD.pdf (Software Design Document v0.1, 2026-10-13)
Stack: Laravel 12, PHP 8.2, MySQL (InnoDB), Blade + Bootstrap 5.3 + jQuery 3.7.1 / jQuery UI 1.14, ClamAV
Audience: AI coding agents and human engineers working on this repository

1. Mission
   Build and maintain the Document Management System (DMS) — a single Laravel 12 application that serves as the centralized repository for documents, versions, sharing, audit trail, and received transaction history for four independent ERP systems (EMS, PMS, ES, OAS).

The DMS is one application, one MySQL database, one private file store. No microservices. No managed cloud DB/queue/search/object storage.

2. Non-Negotiable Constraints

# Constraint Source

C1 Single Laravel 12 app on PHP 8.2, four logical tiers (Presentation, Application, Integration, Persistence) Section 7.1, Section 7.2
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
+--> [2 Application: Laravel 12 / PHP 8.2]
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
   Role Own files Shared files Source documents Received history Accounts and audit
   Employee Read, write Per grant None None None
   Registrar — — — — —
   HR officer — — — — —
   Office / Admin — — — — —
   Share permissions: Viewer, Commenter, Editor.

Editor can rename the document and upload new versions (revision-checked).

Editor cannot move, delete, or reshare. (FR-03)

Comments: max 2,000 characters, with activity sidebar. (FR-04)

Account rules:

Admin creates accounts, changes roles, activates/deactivates. (FR-05)

No self-demotion or self-deactivation. (FR-05, Section 10.1)

New accounts inherit the administrator's scope. (Section 10.1)

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
   Framework: Laravel 12 conventions; PHP 8.2 typed properties, constructor promotion, enums where useful.

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
Client clarification (2026-10-17): Super admins manage their own DMS-source documents and folders in /my-workspace, and oversee EMS/PMS/ES/OAS repositories read-only, including ERP files they previously owned. Enforce this distinction server-side. Account and connector administration remains available. DMS is a personal document source, not a fifth ERP connector.

Role hierarchy (client-approved 2026-10-17): super_admin manages system accounts/connectors, sees all ERP repositories read-only, and can write its own DMS personal workspace. admin manages Staff accounts and document/folder content only in its assigned ERP source, and sees source-scoped audit entries. staff works with owned/shared documents and cannot manage users, folders or share permissions. New approval, backup, SSO/2FA and notification modules are outside this implementation pass.

Phase 2 clarification (2026-10-23): `users.source` stores only `EMS`, `PMS`, `ES` or `OAS`. Super Admin has `source = null` and sees all four. DMS is the application and is not offered as an account source or a fifth ERP source. The existing Super Admin `/my-workspace` is retained for now and is a follow-up to reconcile with the "DMS is not a document source" decision.

Named document shares and folder grants may cross ERP source boundaries as a deliberate, documented exception. School/campus scope and recipient validity still apply; source isolation still applies to ordinary workspace/repository access.

Legacy role handling: `employee`, `teacher` and `hr` map to `staff` + `EMS`; `payroll` maps to `staff` + `PMS`. Legacy `registrar` and legacy `admin` are not auto-migrated and remain follow-ups.
