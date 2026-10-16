# Final SDD implementation alignment

Updated 16 October 2026. Source: [DMS-SDD.pdf, dated 13 October 2026](reference/DMS-SDD-2026-10-13.pdf). Although the PDF cover says draft v0.1, the user designated it as the final implementation plan and explicitly confirmed its staff-only access and scanning requirements. Unresolved hosting/privacy/service-level placeholders remain unresolved; this update does not fabricate approvals.

## Decisions replacing the 10 October plan

- Students are not DMS users. Student sign-in and existing authenticated sessions are denied. Existing accounts/files remain stored; no destructive conversion or deletion occurs.
- Teaching and non-teaching staff use Employee access. Existing teacher roles remain compatible personal-staff aliases; new account creation exposes Employee, Registrar, HR, Payroll and Admin only.
- ClamAV scanning is required. Valid bytes are stored privately; Pending, Failed, Quarantined and legacy Not required versions cannot download, preview or create shares. Existing pinned shares also enforce a clean result on every download.
- A clean scan does not approve business evidence. Admission, enrollment, employment and payroll decisions stay outside DMS.
- The Laravel/PHP/jQuery/Bootstrap/MySQL stack, four logical tiers, five independent databases, named version-pinned audience grants and received-history contracts remain.

## Requirement-to-code map

| Requirement | Implementation / evidence | Remaining limit |
|---|---|---|
| FR-01 / NFR-02: validated, scanned immutable file content | DocumentService, FileScanner; type/signature/size validation; private UUID paths and SHA-256; download/preview/share gates | Tests fake process outcomes. Real scanner/signature-database health not verified. |
| FR-02: folders and Drive organization | DriveController; grid/list, folder tree, Home sections, rename/move/stars/Trash/restore; sequential <=50-file intake | Current app visual comparison not completed. |
| FR-03/04: viewer/commenter/editor and comments/activity | Named expiring grants, pinned version, live authorization; editor rename/new version; bounded escaped comments | No folder sharing or browser document-content editor. |
| FR-05/09 / NFR-01: staff accounts and scopes | Server rejects students including existing sessions; admin role options exclude students; school/campus/source and personal ownership enforced | SSO/MFA and identity mapping remain EA decisions. |
| FR-06/07 / NFR-03: received history and replay | Canonical receipts, unique keys, scoped record locks/CAS, newer-only snapshots, tombstones and cursor reads | Four real source adapters/outboxes and conformance fixtures pending. |
| FR-08: activity | Existing audit plus scan-retry audit/event; retrieval and grant actions audited | Externally tamper-proof storage and retention policy not claimed. |
| NFR-03: upload retry status | New upload 201; identical upload receipt replay 200; changed content/stale revisions 409 | MySQL contention verification remains open. |
| NFR-04: limits | 10 MB/file, 256 KiB/history body, 50 files/folder batch; existing API throttle | Authenticated connector identities have independent limits even behind one IP; covered by regression test. |
| NFR-06/07/08 | Existing recovery/privacy plan retained | Hosting, RPO/RTO, region, retention/hold/disposal and operational owners require approval. |
| NFR-09: usable staff UI | Figma-based centered login, source-aware light sidebar, search, scoped Home statistics/sections, contextual help and jQuery UI dialogs | No formal WCAG certification; browser/mobile verification pending. |

## Figma update

File: [Institutional prototype](https://www.figma.com/design/IiG8PDygbILuSLX5OxaqPt/Untitled?node-id=0-1).

Read references: sidebar `1:451`, sign-in `1:547`, Home `1:617`. These supplied editable typography/layout references; no new React/Tailwind runtime was introduced. Figma contained no local variables, styles or Code Connect files; existing components were reused.

Added employee workspace `27:1498` and My files/Recent/Starred/Shared with me/Trash frames `29:1504`, `29:1550`, `29:1596`, `29:1642`, `29:1688`, with navigation between the employee destinations. Added pending `27:1441`, quarantined `27:1460` and failed scan `27:1479` dialogs. Upload now enters pending, with explicit prototype simulation of a clean scan and failed-scan retry. The clean dialog/copy and walkthrough were corrected. HR/Payroll sample evidence was corrected to its own source.

These are editable prototype states, not live API integrations. Any “Simulate clean scan” control exists only in Figma; the application has no scan-bypass button. Sample capacity figures in the prototype are illustrative and are not implemented quotas or live storage measurements.

## Scanner operation and existing records

1. Provision a trusted ClamAV `clamscan` executable and current signature database on the application host.
2. Set `DMS_CLAMSCAN` to its absolute executable path in local/server configuration. Do not commit credentials or machine-specific configuration.
3. Back up MySQL and private files together. Rehearse/apply the additive 16 October migration, which moves legacy Not required versions to Pending without changing their bytes or history.
4. Run `php artisan dms:scan` to process Pending, Failed and legacy Not required versions. The command validates checksum first and does not automatically release quarantined evidence.
5. Check results, scan audit/events and representative authorized downloads. A missing scanner returns a command error; failed/missing/tampered files stay blocked.

Scan process exit 0 means Clean, 1 means Quarantined, any other exit/exception means Failed. Missing configuration means Pending. Upload scan execution is synchronous with a 45-second process timeout. Scanning currently occurs inside the upload transaction; measure lock time and throughput on MySQL before approving production capacity. The retry command uses chunked reads and document locks with conditional result updates.

The scanner is not installed/configured in the present local environment. Mocked tests establish application branching, not malware-detection efficacy. Existing historical Clean results are retained; review synthetic/demo and previously imported scan provenance before real deployment, and rescan where provenance is uncertain. Never manufacture a clean result to enable access.

## Verification and release boundary

Latest local backend suite: 34 tests / 453 assertions, including student exclusion, duplicate names, replay, owner/grant access, non-clean download/preview/share denial, scanner outcomes and retry. Folder-upload retry/structure and staff password/search frontend checks, JavaScript syntax and Blade compilation pass. Tests use isolated SQLite and fake process results.

Not established: real ClamAV/EICAR exercise, MySQL multi-writer load, source integration, restore drills, production hosting, full browser/mobile visual parity or formal accessibility compliance. Complete these evidence gates before describing the application as production-ready.
