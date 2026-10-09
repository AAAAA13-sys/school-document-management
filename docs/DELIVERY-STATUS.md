> Historical story matrix. Current requirements are ARCHITECTURE.md and WORKFLOW-OWNERSHIP.md. DMS review stories are superseded; MySQL is the target database. The current local suite has 22 tests and 144 assertions.

# Delivery status — baseline v0.1

The supplied plan is preserved as the original proposal. This first working slice is not full R1 completion. “Partial” means some acceptance conditions remain; “Implemented slice” is not production certification. Live ERP services, SSO, ClamAV operation and recovery targets have not been validated.

| Story | Release | Status | Evidence / remaining work |
|---|---|---|---|
| US-01 | R1 | Partial | Local session login works; school identity/SSO remains pending. |
| US-02 | R1 | Partial | Source-scoped staff roles work; permission administration and export grants remain pending. |
| US-03 | R1 | Partial | School/campus boundaries are tested; cross-campus grants remain pending. |
| US-04 | R1 | Partial | Fixed category catalogue works; configurable fields and retirement remain pending. |
| US-05 | R1 | Partial | Classifications stored; fine-grained classification rules and exports remain pending. |
| US-06 | R1 | Partial | Private upload and receipts work; authoritative external record validation remains pending. |
| US-07 | R1 | Partial | Validation, actual ClamAV adapter and blocked pending files work; no scanner installed/validated in this environment. |
| US-08 | R1 | Partial | Metadata validation works; metadata editing/history and authoritative linked-record checks remain pending. |
| US-09 | R1 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-10 | R1 | Partial | Metadata details available; safe file preview not implemented. |
| US-11 | R1 | Partial | Version downloads, checksum checks, access control and audit work; expiring download grants remain pending. |
| US-12 | R1 | Implemented slice | Immutable versions, approval separation and revision conflict checks tested. |
| US-13 | R1 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-14 | R1 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-15 | R1 | Partial | Clean uploads enter review; explicit draft/submission lifecycle remains pending. |
| US-16 | R1 | Partial | Scoped queues and conflicting-decision prevention work; assignment and overdue tracking remain pending. |
| US-17 | R1 | Partial | Version approval and reviewer separation work; requirement recalculation remains pending. |
| US-18 | R1 | Partial | Rejection reasons and replacement history work; originating-system notifications remain pending. |
| US-19 | R1 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-20 | R1 | Partial | Expiry dates stored through datepicker; validity recalculation and reminders remain pending. |
| US-21 | R1 | Partial | Scoped metadata search and source/status filters work; category/date filters remain pending. |
| US-22 | R1 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-23 | R1 | Partial | Scoped total/pending/approved/scan counts work; overdue workload and refresh-time display remain pending. |
| US-24 | R1 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-25 | R1 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-26 | R2 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-27 | R2 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-28 | R1 | Partial | Hashed source/school/campus connector tokens work; finer action grants and management UI remain pending. |
| US-29 | R1 | Partial | Versioned paginated API and integration tests work; full contract/schema validation remains pending. |
| US-30 | R1 | Partial | Actor-scoped replay and changed-payload conflict tested; keys retained indefinitely, expiry decision pending. |
| US-31 | R1 | Partial | Source and reference retained; identity mapping and exception queues remain pending. |
| US-32 | R1 | Partial | Transactional ordered minimal events and pull feed work; push delivery remains pending. |
| US-33 | R1 | Partial | Cursor replay available; push retries and failure queue remain pending. |
| US-34 | R1 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-35 | R1 | Partial | Admission API boundary implemented; no live admission vendor connection. |
| US-36 | R1 | Partial | Enrollment API boundary implemented; confirmed applicant/student mapping and reuse remain pending. |
| US-37 | R1 | Partial | Employee source boundary implemented; live status synchronization remains pending. |
| US-38 | R1 | Partial | Restricted payroll ingestion/versioning boundary implemented; employee self-service/pay-run validation remains pending. |
| US-39 | R1 | Partial | Scoped audit captures uploads/views/downloads/reviews; permission changes/disposal do not exist yet. |
| US-40 | R1 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-41 | R1 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-42 | R2 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-43 | R1 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-44 | R1 | Planned | Planned in the supplied baseline; not implemented in this first slice. |
| US-45 | R1 | Planned | Planned in the supplied baseline; not implemented in this first slice. |

## Verified locally

- PHP 8.2.12, Laravel 12.69.3; Composer lock retained.
- Bootstrap 5.3.8 CSS, jQuery 3.7.1, jQuery UI 1.14.2 served locally.
- 23 automated tests, 141 assertions; in-memory test database isolated from demo data.
- Browser sign-in, dashboard, review queue, document dialog, version tab, synthetic approval, upload tabs and expiry calendar verified.
- No browser console errors observed during those checks.
- Production connections and release performance targets remain unverified.

## Central history development increment

Source-scoped `/api/v1/history` now ingests complete JSON snapshots with immutable receipts, event replay protection, unique record revisions, transactional audit, compare-and-set updates and deletion tombstones. Scoped cursor reads are available to connectors and staff. Existing 45-story status rows still describe the document baseline; this increment does not complete live integration, source outboxes, payload-specific permissions, scheduled reconciliation or production concurrency testing. See `HISTORY-SYNC.md` for the implemented contract and limits.

## Drive workspace development increment

Nested folders, breadcrumbs, grid/list browsing, Recent, personal stars, rename/move, recoverable Trash, clean/checksummed previews and time-limited named sharing are now implemented. These extend the original story status rows without claiming full completion. Sharing does not override source permissions; Trash is not permanent disposal. See `DRIVE-WORKSPACE.md` for behavior, validation and remaining limits.
