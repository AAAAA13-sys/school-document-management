> Historical SDD roadmap. For the latest client decisions, priorities and team handoff, use [the ongoing integration implementation plan](DMS-INTEGRATION-IMPLEMENTATION-PLAN.md), especially sections 14–15 (18 October 2026). Planning only: Super Admin authority, sensitive-change approvals and invitation-only account lifecycle are not implemented by this update.

IMPLEMENTATION PLAN — DMS
Basis: DMS-SDD v0.1. Dates/phases pending Enterprise Architect (Section 12.1).
Delivery principle: Hosting-neutral; configuration-driven; one Laravel app; low cost.

Phase 0 — Foundations & Contract Freeze (Weeks 1–2)
Goal: Lock interfaces before parallel work begins.

# Task Owner Output

0.1 Confirm hosting model decision (O1/AD-07) EA AD-07 updated
0.2 Publish /api/v1 contract (JSON: event ID, record identity, operation, revision) DMS team + ERP SAs OpenAPI/JSON schema
0.3 Agree record types and payload schemas with each ERP team DMS + EMS/PMS/ES/OAS Schema doc
0.4 Confirm data classification and retention schedule Client / DPO Signed classification
0.5 Set up repo, CI skeleton, env config (dev/test/SIT/UAT/prod placeholders) DMS team Repo + CI
0.6 Scaffold Laravel 12 / PHP 8.2 app; MySQL InnoDB; private storage path DMS team Base app
0.7 Install ClamAV locally; wire scan-retry Artisan command stub DMS team AD-01 implemented
Exit: Contract signed off by all four ERP Solution Architects; repo builds; ClamAV reachable.

Phase 1 — Core Persistence & Domain (Weeks 3–5)
Goal: All entities, migrations, constraints, CAS primitives.

# Task Traces

1.1 Migrations: users, folders, documents, document_versions, shares, comments, stars Section 8.1
1.2 Migrations: source_record_snapshots, received_history_receipts, audit_entries, integration_events, connector_identities, request_receipts Section 8.1
1.3 Unique constraints + revision CAS support at DB level C7, NFR-03
1.4 Models + relationships + enums (roles, permissions, scan_status, classification) Section 5, Section 8.2
1.5 DocumentService skeleton: validate, checksum, store, scan, version FR-01, C4, C5
1.6 Private storage layout: storage/app/private/documents/<version UUID> C3
1.7 Audit pipeline: actor, action, document FR-08, C8
1.8 Seeders: roles, test school/campus scopes, synthetic data Section 11.2
Exit: Migrations run clean; unit tests for CAS, checksum, audit pass.

Phase 2 — Integration Tier (/api/v1) (Weeks 5–8)
Goal: Idempotent, revision-safe, scoped API.

# Task Traces

2.1 Bearer token auth: hashed storage, bound to source + school + campus NFR-01, C11
2.2 Throttle middleware: 120 req/min per connector NFR-04
2.3 HistoryController: intake with request receipts + canonical request hash FR-06, NFR-03
2.4 Idempotency logic: 201 / 200 / 409 / applied=false paths C7, Section 9
2.5 Revision CAS + unique constraints + bounded retry on contention NFR-03
2.6 Tombstone handling for deletes; keep earlier payloads Section 9
2.7 Cursor-based read-back endpoints (history, events, documents, files) FR-07, INT-05
2.8 Limits enforcement: 10 MB file, 256 KiB payload, 50 files per batch NFR-04
2.9 Integration events emitted for ERP consumers Section 8.1
2.10 Contract tests: replay gives 200, altered hash gives 409, out-of-order gives applied=false Section 12.3
Exit: All critical API paths pass; contract test suite green.

Phase 3 — Presentation Tier (Workspace) (Weeks 8–12)
Goal: Staff-facing workspace per FR-02, FR-03, FR-04, FR-05.

# Task Traces

3.1 Session auth: active-account check, CSRF, sign-in throttle NFR-01
3.2 Role-aware navigation (Employee, Registrar, HR officer, Admin) Section 5, Section 4
3.3 DriveController: nested folders, rename, move, star, trash, restore FR-02
3.4 Grid and list views; safe previews (formats TBD O3) FR-02
3.5 ShareController: Viewer / Commenter / Editor grants; Editor rename + new version (revision-checked); no move/delete/reshare FR-03
3.6 Comments (up to 2,000 chars) + activity sidebar FR-04
3.7 AccountController: create, change role, activate/deactivate; no self-demotion/deactivation; inherit admin scope FR-05, Section 10.1
3.8 History and admin views: received vs current state FR-07
3.9 Audit views for admins FR-08
3.10 Accessibility pass: keyboard nav, readable text, accessible navigation NFR-09
Exit: Acceptance scenarios per office role pass; reviewer sign-off (Client, EMS/PMS/ES/OAS SAs).

Phase 4 — Security, Compliance & Hardening (Weeks 12–14)

# Task Traces

4.1 Full file pipeline hardening: actual-content validation, 10 MB cap, UUID paths, SHA-256 re-verify on access, scan blocking C4, NFR-02
4.2 School/campus checks on every shared operation C11
4.3 Audit coverage: uploads, downloads, modifications, deletions, access changes = 100% OBJ-05
4.4 DPIA execution before go-live (O14) Section 8.3, Section 10.4
4.5 Children's personal data handling for ES/OAS payloads; restrict to office/admin Section 8.3, R-05
4.6 Decide: encryption at rest, monitoring, pen testing (O9) Section 10.3
4.7 Security tests: upload validation, scan blocking, token and role scoping Section 12.3
4.8 TLS/HTTPS-only verification Section 10.4
Exit: No open critical/high security findings.

Phase 5 — Operations, Backup & DR (Weeks 14–16)

# Task Traces

5.1 Joint MySQL + private files backup (single backup set) NFR-06, AD-03
5.2 Tested restore; files match stored checksums Section 12.3
5.3 Monitoring: files stuck in Pending / Failed / Quarantined Section 11.3
5.4 Scan-retry Artisan command in production schedule AD-01, R-06
5.5 Alerting for persistent conflicts (O12) Section 9
5.6 Support tiers and SLAs (O11) Section 11.3
5.7 Backup/restore test frequency agreed (O10) NFR-06
Exit: Restore rehearsal passes; monitoring live; SLAs documented.

Phase 6 — Migration & Cutover (Weeks 16–20)

# Task Owner Traces

6.1 Each source: commit local change + outbox entry together; send initial snapshot with increasing revisions + stable event ID EMS/PMS/ES/OAS teams Section 12.2
6.2 DMS stores snapshot + receipt; source reconciles via cursor reads DMS team Section 12.2
6.3 Rehearsal loads; reconcile record counts per source with each reviewer DMS + reviewers Section 12.2
6.4 Identity matching, payload schemas finalized DMS + ERP SAs Section 12.2
6.5 Cutover per source (window agreed with EA) DMS + ERP SAs Section 12.2
6.6 Full import, tested restore, SSO coordination if approved To be assigned Section 12.1
6.7 Training: role-based guides for office staff; /api/v1 integration guide DMS team Section 12.4
Exit: Each reviewer signs off per ERP system and office role.

Phase 7 — Concurrency & Load Validation (Parallel, Weeks 16–18)

# Task Traces

7.1 Multi-writer MySQL load test R-04, Section 11.3
7.2 Lock and unique-constraint behavior under load Section 12.3
7.3 Verify no lost or duplicated history under load Section 12.3
7.4 Bounded retry + 409 handling validation Section 9
Exit: No lost or duplicated history under load.

Cross-Phase Traceability Matrix
Requirement Component Test
FR-01, NFR-02 DocumentService, private storage, scanner Unit/integration; security
FR-02, FR-03, FR-04 DriveController, ShareController Acceptance
FR-05, FR-09, NFR-01 AccountController, session security, IntegrationAuth Security
FR-06, FR-07, NFR-03 HistoryController, MySQL Unit/integration; concurrency
FR-08 DocumentService, audit entries Acceptance
NFR-06 Backup of MySQL + private files Backup/restore
Risk Register (from SDD Section 13.1 — track through delivery)
ID Risk Owner Mitigation status
R-01 Hosting model not settled EA Open — Phase 0.1
R-02 ERP adapters late / revisions not increasing ERP SAs Open — Phase 0.2, 6.1
R-03 MySQL + files not backed up together DMS SA Open — Phase 5.1
R-04 Multi-writer MySQL contention untested DMS team Open — Phase 7
R-05 Student/applicant data in ES/OAS payloads Client/DPO Open — Phase 4.4, 4.5
R-06 Scanner unavailable blocks uploads DMS team Open — Phase 5.4
Milestone Summary
Milestone Phase Gate
M1 — Contract frozen 0 All ERP SAs sign /api/v1
M2 — Core persistence ready 1 Migrations + CAS tests pass
M3 — API idempotent & revision-safe 2 Contract tests green
M4 — Workspace accepted 3 Reviewer sign-off
M5 — Security & compliance cleared 4 No critical/high findings
M6 — Ops & DR proven 5 Restore rehearsal passes
M7 — Cutover per source 6 Per-source reconciliation
M8 — Load validated 7 No lost/duplicated history
Approval requested (SDD Section 2): Target architecture (Sections 7–10) by [date]. Hosting (Section 11) remains open pending the Enterprise Architect.
