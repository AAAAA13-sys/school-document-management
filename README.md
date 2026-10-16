# Pamantasan ng Lungsod ng Pasig (PLP) — Document Management System (DMS)

> **Centralized Document Repository & Integration Hub for University ERP Ecosystem**  
> Built for EMS (Enrollment Management), PMS (Personnel Management), ES (Executive System), and OAS (Online Admissions).  
> **Tech Stack:** Laravel 12 · PHP 8.2 · MySQL InnoDB · Blade + Bootstrap 5.3 + jQuery UI · ClamAV Local Engine.

---

## 📌 Implementation Progress Dashboard (~50% Complete)

| Phase       | Description                                |     Status      | Completion | Reference                                                                                                   |
| :---------- | :----------------------------------------- | :-------------: | :--------: | :---------------------------------------------------------------------------------------------------------- |
| **Phase 0** | Foundations & Contract Freeze              |    **Done**     |    100%    | [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md#phase-0--foundations--contract-freeze-weeks-12) |
| **Phase 1** | Core Persistence & Domain Architecture     |    **Done**     |    100%    | [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md#phase-1--core-persistence--domain-weeks-35)     |
| **Phase 2** | Integration Tier (`/api/v1`)               |    **Done**     |    100%    | [`docs/API.md`](docs/API.md)                                                                                |
| **Phase 3** | Presentation Tier (Workspace & UI)         | **In Progress** |    90%     | [`docs/USER-GUIDE.md`](docs/USER-GUIDE.md)                                                                  |
| **Phase 4** | Security, Compliance & Hardening           | **In Progress** |    50%     | [`docs/AGENTS.md`](docs/AGENTS.md#7-security-rules)                                                         |
| **Phase 5** | Operations, Backup & Disaster Recovery     | **In Progress** |    40%     | [`docs/OPERATIONS.md`](docs/OPERATIONS.md)                                                                  |
| **Phase 6** | Migration & ERP Source Cutover             |   **Pending**   |     0%     | [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md#phase-6--migration--cutover-weeks-1620)         |
| **Phase 7** | Concurrency & Multi-Writer Load Validation | **In Progress** |    30%     | [`docs/VERIFICATION.md`](docs/VERIFICATION.md)                                                              |

---

## 🚀 Quick Start (1-Click Kickstarter)

Double-click `start.bat` or run in terminal:

```bat
start.bat
```

This automatically:

1. Verifies/starts MySQL on port `3306`.
2. Prepares the `dms` database and verifies `.env`.
3. Runs database migrations (`php artisan migrate`).
4. Starts the background database scan queue worker (`queue:work`).
5. Starts the task scheduler (`schedule:work`) for retry cycles and FreshClam signature updates.
6. Launches the web server at **`http://127.0.0.1:8011`** and opens your default browser.

### Manual Setup & Verification

```powershell
composer install
npm ci
npm run build

# Copy .env.example if .env does not exist, then configure MySQL:
php artisan key:generate
php artisan migrate

# Seed or create your administrative staff account:
php artisan dms:admin your.admin@plp.edu.ph --school=PLP --campus=Main

# Verify system & scanner health:
php artisan dms:status

# Run local development stack:
powershell -ExecutionPolicy Bypass -File scripts/dev.ps1
```

---

## 📋 Master Checklist of Things to Do

Derived from [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md), [`docs/AGENTS.md`](docs/AGENTS.md), [`docs/API.md`](docs/API.md), and [`docs/DMS-SDD.pdf`](docs/DMS-SDD.pdf).

### Phase 0: Foundations & Contract Freeze (Weeks 1–2) — 100% Completed

- [x] **0.1** Confirm hosting model decision (O1 / AD-07: hosting-neutral configuration-driven model).
- [x] **0.2** Publish `/api/v1` contract (JSON event ID, record identity, operation, revision) in [`docs/API.md`](docs/API.md) and [`docs/openapi.json`](docs/openapi.json).
- [x] **0.3** Agree record types and payload schemas with each ERP team (EMS, PMS, ES, OAS).
- [x] **0.4** Confirm data classification draft (Restricted, Confidential, Internal).
- [x] **0.5** Set up repository, test suites, environment config (`.env.example`).
- [x] **0.6** Scaffold Laravel 12 / PHP 8.2 application; MySQL InnoDB; private storage layout (`storage/app/private/documents/<UUID>`).
- [x] **0.7** Install local ClamAV engine; wire scan-retry Artisan command (`php artisan dms:scan-retry`).

---

### Phase 1: Core Persistence & Domain Architecture (Weeks 3–5) — 100% Completed

- [x] **1.1** Migrations: `users`, `folders`, `documents`, `document_versions`, `shares`, `comments`, `stars`.
- [x] **1.2** Migrations: `source_record_snapshots`, `received_history_receipts`, `audit_entries`, `integration_events`, `connector_identities`, `request_receipts`.
- [x] **1.3** DB-level unique constraints and Compare-And-Swap (CAS) revision support for race prevention.
- [x] **1.4** Eloquent models with typed properties, strict relationships, and enums (`roles`, `permissions`, `scan_status`, `classification`).
- [x] **1.5** `DocumentService` core pipeline: mime detection, SHA-256 checksum, private UUID storage, async ClamAV enqueue, immutable version increments.
- [x] **1.6** Private storage isolation: strictly non-web-accessible storage layout.
- [x] **1.7** Comprehensive audit logging pipeline for all sensitive operations (upload, download, modify, delete, access change).
- [x] **1.8** Database seeders and CLI admin creation tools (`php artisan dms:admin`).

---

### Phase 2: Integration Tier (`/api/v1`) (Weeks 5–8) — 100% Completed

- [x] **2.1** Bearer token authentication: stored hashed in DB, strictly bound to one source (`EMS`, `PMS`, `ES`, `OAS`) + `school` + `campus`.
- [x] **2.2** Per-connector throttle middleware: enforced limit of 120 req/min with `Retry-After` headers.
- [x] **2.3** `HistoryController`: atomic transaction intake with request receipts and canonical JSON body hashing.
- [x] **2.4** Idempotency engine:
  - Identical event replay returns `200 OK` with original receipt.
  - Same event with altered hash returns `409 Conflict`.
  - Conflicting record revision returns `409 Conflict`.
  - Stale/older revision stored with `applied=false` without overwriting latest snapshot.
- [x] **2.5** Revision CAS with bounded lock/deadlock retries before graceful `409 Conflict`.
- [x] **2.6** Tombstone handling for deletion events while preserving all historical audit receipts.
- [x] **2.7** Cursor-based pagination for read-back endpoints (`/history`, `/events`, `/documents`, `/files`) with `?after=cursor&limit=50`.
- [x] **2.8** Body and upload limit enforcement: max 10 MB per file, max 256 KiB history payload, max 50 files per folder batch.
- [x] **2.9** Integration event publishing for ERP downstream consumer synchronization.
- [x] **2.10** Automated contract verification suite passing all idempotency, replay, and tamper tests.

---

### Phase 3: Presentation Tier (Workspace & UI) (Weeks 8–12) — 90% Completed

- [x] **3.1** Session authentication with active-account verification, CSRF tokens, and brute-force sign-in throttling.
- [x] **3.2** Responsive, non-scrollable modern institutional login interface matching university guidelines and strict typography.
- [x] **3.3** Role-aware navigation & permission matrix (`super_admin`, `admin`, `staff`):
  - Super Admin: oversees system accounts, connectors, read-only ERP document browsing, and personal DMS workspace.
  - Admin: manages source-scoped staff accounts and files within designated ERP domain.
  - Staff: personal files, shared files, folder navigation.
- [x] **3.4** Drive file manager: nested folders, breadcrumb navigation, move, rename, star, soft-trash, and restore.
- [x] **3.5** Streamlined file-first upload dialog with folder targeting and automated filename-to-title binding.
- [x] **3.6** Safe document preview modal: local PDF.js integration, scanned raster images, CSV/text viewer without file leakage.
- [x] **3.7** `ShareController`: granular Viewer, Commenter, and Editor grants (Editor can rename and upload revisions; cannot delete/move/reshare).
- [x] **3.8** Document comments (up to 2,000 characters) and historical activity sidebar.
- [x] **3.9** Account administration: create staff, edit roles, toggle activation, reset passwords (with no self-demotion/deactivation).
- [ ] **3.10** Final UI review and sign-off by university client and ERP solution architects.

---

### Phase 4: Security, Compliance & Hardening (Weeks 12–14) — 50% Completed

- [x] **4.1** File pipeline security: actual byte validation, 10 MB cap, SHA-256 integrity verification before every download.
- [x] **4.2** ClamAV quarantine & fail-closed security: pending, failed, or quarantined files strictly return `423 Locked`.
- [x] **4.3** Institutional multi-tenant isolation: strict school/campus/source validation on every operation.
- [x] **4.4** 100% audit logging coverage for sensitive document operations.
- [ ] **4.5** Execute formal Data Privacy Impact Assessment (DPIA) under RA 10173 (Philippine Data Privacy Act) (O14).
- [ ] **4.6** Enforce specific protection and access controls on children/minor personal data in ES and OAS payloads (R-05).
- [ ] **4.7** Finalize architectural decisions on database encryption at rest (O9) and centralized SIEM log shipping.
- [ ] **4.8** Production HTTPS/TLS certificate configuration and HTTP Strict Transport Security (HSTS) validation.
- [ ] **4.9** External third-party vulnerability assessment & penetration testing (VAPT).

---

### Phase 5: Operations, Backup & Disaster Recovery (Weeks 14–16) — 40% Completed

- [x] **5.1** ClamAV supervisor runbook and scheduled retry worker (`php artisan dms:scan-retry`).
- [x] **5.2** FreshClam automated hourly signature updates via Laravel scheduler (`php artisan dms:signatures-update`).
- [x] **5.3** Health check status command (`php artisan dms:status`) with benign-file test.
- [x] **5.4** Offline coordinated backup procedure documented (joint MySQL dump + `storage/app/private/documents/` archive).
- [ ] **5.5** Automated daily joint backup script with checksum verification manifest (NFR-06 / AD-03).
- [ ] **5.6** Formal restore rehearsal on staging target host with timed RTO/RPO verification (O4 / O10).
- [ ] **5.7** Alerting and notification triggers for persistent revision conflicts and scanner downtime (O12).
- [ ] **5.8** Agreement and publishing of operational support tiers and SLA definitions (O11).

---

### Phase 6: Migration & ERP Source Cutover (Weeks 16–20) — 0% Pending

- [ ] **6.1** ERP Source Adapter readiness:
  - EMS adapter outbox and monotonic revision emitter.
  - PMS adapter outbox and monotonic revision emitter.
  - ES adapter outbox and monotonic revision emitter.
  - OAS adapter outbox and monotonic revision emitter.
- [ ] **6.2** Initial data snapshot import rehearsals and record count reconciliations per source.
- [ ] **6.3** End-to-end integration test with live ERP connector credentials in SIT environment.
- [ ] **6.4** Production cutover window planning and scheduling with University Enterprise Architect.
- [ ] **6.5** Staff user training and role-based operational guide handoff.

---

### Phase 7: Concurrency & Multi-Writer Load Validation — 30% Completed

- [x] **7.1** Subprocess concurrency test suite (`phpunit.mysql.xml`):
  - Concurrent identical submission yields single receipt.
  - Concurrent divergent submissions at same revision yields one commit and one conflict.
- [x] **7.2** MySQL row-lock and transaction boundary verification under isolated test database (`dms_test`).
- [ ] **7.3** Multi-writer load simulation up to peak campus registration concurrent thresholds (O2 / R-04).
- [ ] **7.4** Stress-testing queue workers during sustained 50-file bulk folder intake bursts.

---

## 🔒 Open Architectural Decisions (TBD Register)

| ID      | Open Item                           | Stakeholder / Owner       | Current Mitigation / Direction                                             |
| :------ | :---------------------------------- | :------------------------ | :------------------------------------------------------------------------- |
| **O1**  | Hosting model (On-prem vs Cloud)    | Enterprise Architect      | Application is hosting-neutral; paths and DB are configuration-driven.     |
| **O2**  | Peak concurrency volumes            | University Client         | Rate limiting set to 120 req/min per connector; 10 MB per file cap.        |
| **O3**  | Approved preview format whitelist   | University Client         | Safe rendering for PDF (PDF.js), PNG/JPG/WebP, CSV, JSON, and TXT.         |
| **O4**  | RPO / RTO targets                   | IT Operations / EA        | Daily coordinated cold backup script planned; fail-closed architecture.    |
| **O8**  | Institutional SSO / 2FA             | Enterprise Architect      | Password policies enforced (12+ chars, complex); SSO hooks modularized.    |
| **O9**  | Encryption at rest                  | IT Security / DPO         | Storage uses UUID naming in private filesystem; DB credentials restricted. |
| **O10** | Backup restore test frequency       | IT Operations             | Scheduled quarterly restore rehearsals to staging environment.             |
| **O11** | Support tiers & SLAs                | Project Management        | Standard university helpdesk escalation paths to be defined.               |
| **O12** | Persistent conflict alert recipient | ERP Integration Leads     | Logged to integration audit trail; email/webhook alert target pending.     |
| **O14** | Formal DPIA sign-off                | University DPO            | Full compliance mapping with RA 10173 ready for DPO review.                |
| **O16** | Data classification schedule        | University Registrar / HR | Three-tier classification implemented: Internal, Confidential, Restricted. |

---

## ⚠️ Risk Register & Mitigation Strategy

| Risk ID  | Description                                       |  Impact  | Mitigation Strategy                                                                                         |
| :------- | :------------------------------------------------ | :------: | :---------------------------------------------------------------------------------------------------------- |
| **R-01** | Hosting model decision delayed                    |   Med    | Self-contained Laravel 12 architecture runs on standard Linux/Windows or cloud VM without vendor lock-in.   |
| **R-02** | External ERP adapters send out-of-order revisions |   High   | Monotonic revision CAS in MySQL InnoDB: older revisions flagged `applied=false`, snapshots never corrupted. |
| **R-03** | MySQL and file storage backed up out of sync      | Critical | Single joint backup procedure: database dump and private file tree archived together with SHA-256 manifest. |
| **R-04** | High concurrency lock contention on MySQL         |   Med    | Granular row-level locks, bounded retry loops with exponential backoff, and idempotent receipts.            |
| **R-05** | Minor/student privacy in ES/OAS payloads          |   High   | Strict role-based scoping; students are never DMS users; payloads restricted to authorized office staff.    |
| **R-06** | Local ClamAV scanner unavailable or offline       |   High   | Uploads commit to `Pending` queue; files remain blocked (`423`) until verified Clean by supervised worker.  |

---

## 🧪 Testing & Quality Assurance Runbook

```powershell
# Run full PHPUnit test suite (SQLite in-memory):
php artisan test

# Run MySQL real concurrency & row-lock suite (requires dms_test database):
php vendor/bin/phpunit -c phpunit.mysql.xml

# Run with real ClamAV engine verification (requires local ClamAV):
$env:DMS_REAL_CLAMAV_TESTS='1'
php artisan test --filter=ClamAvIntegrationTest
Remove-Item Env:DMS_REAL_CLAMAV_TESTS

# Run frontend test suite (Node test runner):
npm test

# Build production assets:
npm run build

# Check code formatting (Prettier):
npm run format:check

# Auto-format all views, scripts, and stylesheets:
npm run format
```

---

## 📂 Documentation Directory Map

- [`docs/AGENTS.md`](docs/AGENTS.md) — Comprehensive technical architecture, constraints, role hierarchy, and development guidelines.
- [`docs/IMPLEMENTATION_PLAN.md`](docs/IMPLEMENTATION_PLAN.md) — Original phased delivery plan and cross-phase traceability matrix.
- [`docs/API.md`](docs/API.md) — ERP integration contract, payload schemas, idempotency rules, and curl examples.
- [`docs/SETUP.md`](docs/SETUP.md) — Detailed standalone setup, environment configuration, and ClamAV installation guide.
- [`docs/USER-GUIDE.md`](docs/USER-GUIDE.md) — Staff user manual for document management, sharing, folders, and previews.
- [`docs/OPERATIONS.md`](docs/OPERATIONS.md) — Supervisor daemon setup, queue worker tuning, backups, and disaster recovery.
- [`docs/VERIFICATION.md`](docs/VERIFICATION.md) — Backend and frontend verification logs and test execution reports.
- [`docs/openapi.json`](docs/openapi.json) — Full OpenAPI 3.0 specification for `/api/v1` endpoints.
- [`docs/DMS-SDD.pdf`](docs/DMS-SDD.pdf) — Complete Software Design Document v0.1 (source of truth).
