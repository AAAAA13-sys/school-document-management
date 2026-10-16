# SDD functional and non-functional requirement register

Split register extracted from the adopted [SDD](reference/DMS-SDD-2026-10-13.pdf) (v0.1 draft, dated 13 October 2026, Mr. Thomas Dominique G. Baran). This file exists so the Functional Requirements and the Non-Functional Requirements can be read, reviewed and assigned separately without re-parsing the PDF.

Extracted 16 October 2026. The PDF is the authority; this register is a copy of its content and cannot contradict it. Where the SDD left a value open, the gap is preserved as `[TBD]` rather than filled in.

- Source section for requirements: SDD §5 (`5.1` Functional, `5.2` Non-functional), pages 6-8.
- Source section for traceability: SDD §7.3 component table and Appendix B, pages 11-12 and 24.
- Priorities are MoSCoW as stated in SDD §5 and are **proposed for client confirmation**, not agreed.
- Requirement text below is verbatim from the SDD, reflowed onto single lines. No wording was changed.

## How to use this file

| Need | Go to |
|---|---|
| Build or review features | [1. Functional requirements](#1-functional-requirements) |
| Build or review quality attributes | [2. Non-functional requirements](#2-non-functional-requirements) |
| Trace a requirement to code | [docs/SDD-ALIGNMENT.md](SDD-ALIGNMENT.md) requirement-to-code map |
| Trace a requirement to a delivery phase | [MASTERPLAN.md](../MASTERPLAN.md) §10 backlog |
| Trace a requirement to a test type | [4. SDD traceability and its gaps](#4-sdd-traceability-and-its-gaps) |

---

## 1. Functional requirements

What the system must do. Nine requirements, FR-01 to FR-09, from SDD §5.1.

| ID | Module | Requirement (SDD §5.1) | Priority |
|---|---|---|---|
| FR-01 | Documents | Upload files as immutable versions after content validation and malware scan; keep every earlier version | Must |
| FR-02 | Workspace | Nested folders, rename, move, star, trash and restore, safe previews, grid and list views | Must |
| FR-03 | Sharing | Document sharing grants: Viewer, Commenter or Editor permissions. Because in-browser file editing is out of scope, an Editor can rename the document and upload new versions (revision-checked). Editors cannot move, delete or reshare. | Must |
| FR-04 | Comments | Comments on shared documents (up to 2,000 characters) and an activity sidebar | Should |
| FR-05 | Accounts | Admin creates accounts, changes roles and activates or deactivates; no self-demotion or self-deactivation | Must |
| FR-06 | History intake | Accept history events from EMS, PMS, ES and OAS; store an immutable receipt; update the latest snapshot only for newer revisions | Must |
| FR-07 | History read-back | Cursor-based reads of history and events through the API; office and admin UI to inspect received versus current state | Must |
| FR-08 | Audit | Record actor, action and document for sensitive actions | Must |
| FR-09 | Scoped access | Limit users and connectors to their school, campus and, for office roles, their source system | Must |

Priority summary: eight Must, one Should (FR-04), no Could, no Won't in the SDD as drafted.

### 1.1 Functional requirements mapped to the delivery backlog

The SDD does not link FR/NFR IDs to the repository's DMS-xx story IDs. The mapping below is **proposed** for confirmation, derived by comparing requirement wording with [MASTERPLAN.md](../MASTERPLAN.md) §10 acceptance criteria.

| ID | Proposed backlog stories |
|---|---|
| FR-01 | DMS-05, DMS-07 |
| FR-02 | DMS-02, DMS-08, DMS-09, DMS-10 |
| FR-03 | DMS-11, DMS-12 |
| FR-04 | DMS-12, DMS-13 |
| FR-05 | DMS-14 |
| FR-06 | DMS-19 |
| FR-07 | DMS-13, DMS-19 |
| FR-08 | DMS-13, DMS-14 |
| FR-09 | DMS-14, DMS-22 |

---

## 2. Non-functional requirements

How the system must behave. Nine requirements, NFR-01 to NFR-09, from SDD §5.2. The SDD gives these no MoSCoW priority; none is invented here.

| ID | Category | Requirement (SDD §5.2) | Agreed? |
|---|---|---|---|
| NFR-01 | Security | Session sign-in with active-account check, CSRF protection and sign-in throttling; connector tokens stored hashed and bound to source, school and campus | Partly — SSO and MFA explicitly not in the current design |
| NFR-02 | Integrity | SHA-256 checksum recorded for every version and re-verified on access; versions are never changed | Yes |
| NFR-03 | Idempotency | A retried request with the same key and content returns the original receipt (200). A changed request or stale revision returns 409. An older revision is stored (applied=false) without changing the latest snapshot. | Yes |
| NFR-04 | Limits | API throttle 120 requests per minute; file up to 10 MB; history payload up to 256 KiB; folder upload up to 50 files per batch | Yes |
| NFR-05 | Availability | `[TBD]` — the SDD records no availability target | **No** |
| NFR-06 | Recovery | MySQL and private files restored together; RPO and RTO `[to be agreed]` | Partly — recovery method stated, targets missing |
| NFR-07 | Privacy | Compliant with [Philippine Data Privacy Act of 2012 (RA 10173), to confirm]; data residency `[to be agreed]` | **No** — statute applicability and residency both unconfirmed |
| NFR-08 | Auditability | All audited actions retained for `[period to be agreed]` | **No** — retention period missing |
| NFR-09 | Accessibility | The DMS must support keyboard navigation, readable text, and accessible navigation features. Formal compliance with WCAG 2.2 Level AA is subject to client approval. | Partly — capabilities stated, certification target unapproved |

Three requirements (NFR-05, NFR-07, NFR-08) have no agreed target value and cannot be verified or signed off as written. They are tracked as open items in [MASTERPLAN.md](../MASTERPLAN.md) §12 release gates rather than being filled in here.

### 2.1 Non-functional requirements mapped to the delivery backlog

**Proposed** for confirmation, same basis as §1.1.

| ID | Proposed backlog stories |
|---|---|
| NFR-01 | DMS-14, DMS-24 |
| NFR-02 | DMS-05, DMS-15 |
| NFR-03 | DMS-16, DMS-19 |
| NFR-04 | DMS-06, DMS-08 |
| NFR-05 | DMS-24 (blocked — no target) |
| NFR-06 | DMS-23 |
| NFR-07 | DMS-22, DMS-23 |
| NFR-08 | DMS-23 |
| NFR-09 | DMS-24 |

---

## 3. Other coded items in the SDD

The SDD uses four more code series. They are not requirements, but reviewers working from the split will need them, so they are indexed here rather than mixed into §1 and §2.

### 3.1 Objectives OBJ-01 to OBJ-05 (SDD §3.3, pages 3-4)

| ID | Objective | Measure | Target |
|---|---|---|---|
| OBJ-01 | Centralized ERP document and history repository | Percentage of integrated ERP systems successfully sending documents and transaction history to the DMS | 100% |
| OBJ-02 | Safe retries and duplicate prevention | Number of duplicate history records created when an event is retried or resent | 0 |
| OBJ-03 | Secure and trusted file storage | Number of files served without passing malware scanning or checksum verification | 0 |
| OBJ-04 | Low operational and maintenance cost | Number of additional database engines required beyond the existing MySQL database | 0 |
| OBJ-05 | Complete audit trail and accountability | Percentage of sensitive actions (uploads, downloads, modifications, deletions and access changes) recorded in the audit log | 100% |

### 3.2 Interfaces INT-01 to INT-06 (SDD §9, pages 15-16)

| ID | Interface | Direction | Pattern | Frequency | Data standard |
|---|---|---|---|---|---|
| INT-01 | EMS | EMS → DMS | REST push, idempotent | Per record change | History events, documents |
| INT-02 | PMS | PMS → DMS | REST push, idempotent | Per record change | History events, documents |
| INT-03 | ES | ES → DMS | REST push, idempotent | Per record change | History events, documents |
| INT-04 | OAS | OAS → DMS | REST push, idempotent | Per record change | History events, documents |
| INT-05 | ERP read-back | DMS → ERP (GET) | REST, cursor-based | On demand | History, events, documents, files |
| INT-06 | Malware scanner | DMS → ClamAV | Command | Per upload and retry | File bytes |

Bearer token and JSON apply to INT-01 to INT-05. All integrations cross the single `/api/v1` boundary.

### 3.3 Risks R-01 to R-06 (SDD §13.1, pages 21-22)

Ratings are proposed in the SDD.

| ID | Risk | Likelihood | Impact | Owner |
|---|---|---|---|---|
| R-01 | Hosting model not settled | Medium | High | Enterprise Architect |
| R-02 | ERP adapters late, or revisions not increasing | Medium | High | ERP Solutions Architects |
| R-03 | MySQL and files not backed up or restored together | Medium | High | DMS Solutions Architect |
| R-04 | Multi-writer MySQL contention untested | Medium | Medium | DMS team |
| R-05 | Student or applicant data inside ES/OAS payloads | Medium | Very high | Client / DPO `[TBD]` |
| R-06 | Scanner unavailable blocks uploads | Medium | Medium | DMS team |

### 3.4 Architecture decisions AD-01 to AD-07 (SDD §13.3, pages 22-23)

| ID | Decision | Status |
|---|---|---|
| AD-01 | Local ClamAV engine plus scan-retry Artisan command instead of commercial managed scanning APIs | Dated 2026-10-13 |
| AD-02 | One Laravel deployment, custom-built, four logical tiers | Date open |
| AD-03 | One MySQL database and private folders | Date open |
| AD-04 | Scoped bearer tokens, idempotent receipts, revision compare-and-swap | Date open |
| AD-05 | File scan is a security control, not business approval | Date open |
| AD-06 | Students are not DMS users | Date open |
| AD-07 | Hosting model: local or on-premises vs cloud | **Open — pending Enterprise Architect** |

---

## 4. SDD traceability and its gaps

SDD Appendix B (page 24) maps requirements to component to test type.

| Requirement | Component | Test |
|---|---|---|
| FR-01, NFR-02 | DocumentService, private storage, scanner | Unit and integration; security |
| FR-02, FR-03, FR-04 | DriveController, ShareController | Acceptance |
| FR-05, FR-09, NFR-01 | AccountController, session security, IntegrationAuth | Security |
| FR-06, FR-07, NFR-03 | HistoryController, MySQL | Unit and integration; concurrency |
| FR-08 | DocumentService, audit entries | Acceptance |
| NFR-06 | Backup of MySQL and private files | Backup and restore |

**Five NFRs are absent from SDD Appendix B:** NFR-04 (limits), NFR-05 (availability), NFR-07 (privacy), NFR-08 (auditability) and NFR-09 (accessibility). No component or test owner is named for them in the SDD. This is recorded as a gap, not repaired here — a traceability row is a client and Enterprise Architect decision.

Note also that SDD Appendix B is coarser than the repository's own map. [docs/SDD-ALIGNMENT.md](SDD-ALIGNMENT.md) covers all eighteen IDs and adds implementation evidence and remaining limits, and should be preferred for code-level tracing.

---

## 5. Provenance and limits

- Source: `docs/reference/DMS-SDD-2026-10-13.pdf`, 24 pages, v0.1 draft. Despite the draft label, the user designated it the final implementation plan on 16 October 2026.
- Text was extracted from the PDF for this register. FR/NFR wording was checked against SDD §5.1/§5.2 and left unchanged. The SDD's NFR-02 sentence ends exactly at the page 7/8 boundary in the source; no continuation was found on page 8, so it is recorded as complete as written.
- Priorities in §1 are the SDD's own proposed MoSCoW values and are **not client-confirmed**. The SDD does not prioritise NFRs at all.
- The backlog mapping in §1.1 and §2.1 and the gap analysis in §4 are this repository's proposed work, not SDD content.
- This register changes no scope, status or priority. [MASTERPLAN.md](../MASTERPLAN.md) remains the product authority and [docs/SDD-ALIGNMENT.md](SDD-ALIGNMENT.md) remains the code-level authority.