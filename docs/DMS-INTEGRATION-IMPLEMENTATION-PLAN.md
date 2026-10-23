# DMS shared repository and ERP integration implementation plan

Prepared and updated: 18 October 2026. Status: planning only, not a completion report.

**Resume here:** section 16 records the latest invitation hierarchy and scope clarifications; it supersedes conflicting earlier medical-scope and email-domain assumptions. Sections 14–15 record the latest client decisions about role authority, sensitive-change approvals, invitation-only accounts and the next team handoff. These decisions supersede the 17 October read-only Super Admin policy and earlier account-creation assumptions. No role, approval or invitation implementation is delivered by this document. The client explicitly stopped implementation and requested planning only.

## 1. Purpose and outcome

DMS will provide controlled document custody across school processes. Each ERP owns its business transactions and decisions; DMS stores the document bytes, immutable versions, file integrity/scan results, access grants and document audit history. Received business events remain evidence supplied by the ERP, not instructions for DMS to repeat the ERP's work.

Two primary demonstrations establish the purpose: (1) a school office circulates an official memo to its authorized staff audience using one preserved document, and (2) OAS releases an admitted applicant's verified packet for authorized ES retrieval. ES references the same DMS document/version IDs instead of requesting the files again or maintaining another independent permanent collection. Applicant and student accounts remain in their originating systems, not in DMS. Sections 9–12 expand the staff scenarios and update delivery priorities.

An ERP may keep a temporary upload spool, cache or delivery outbox for reliability. That is different from keeping a separately editable permanent document archive. Existing PMS local payslip preservation is a documented design conflict: custody and migration must be agreed before removing any source files.

Standalone DMS remains usable with zero ERP credentials configured. Its staff accounts, authorized workspaces, documents and scanning must not depend on ERP availability.

## 2. Scope and document inventory

| Source                      | Initial files                                                                                                                                                                | Reason                                                                                       | Evidence / decision                                                                                           |
| --------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| OAS                         | PSA birth certificate; parent ID; guardianship affidavit where applicable; applicant photo; Form 137 or Grade 11 Form 138 for the specified applicant category; applicant ID | Preserve submitted evidence and resubmissions; reuse the authorized packet during enrollment | Explicit OAS SDD, page 12; per-applicant checklist eligibility remains OAS logic                              |
| OAS / Clinic                | Medical requirements/results                                                                                                                                                 | Preserve Clinic-reviewed evidence                                                            | Explicit OAS requirement; exact items need confirmation; Clinic-only access is a release gate                 |
| OAS                         | Scanned signed exam ticket                                                                                                                                                   | Preserve signed examination evidence                                                         | Optional in OAS SDD, page 14; paper ticket remains separately retained                                        |
| PMS                         | Finalized payslip PDFs and subsequent issued corrections                                                                                                                     | Preserve the exact issued output per employee and pay period                                 | Explicit PMS SDD, pages 2, 4 and 10; custody decision required because PMS also specifies local private files |
| EMS                         | Identified employee attachments needed beyond HR                                                                                                                             | Support an actual HR-to-other-office document exchange                                       | Proposed only: EMS diagram does not supply an attachment inventory; confirm exact documents and recipients    |
| ES                          | Identified issued enrollment documents or special-case supporting files                                                                                                      | Preserve official outputs/evidence beyond an operational record                              | Proposed only: ES diagram does not enumerate file outputs                                                     |
| Standalone departmental use | Review/evaluation reports and agreed supporting evidence                                                                                                                     | Faculty, heads and deans review the same document with preserved versions                    | Professor-requested use case; agree owner, audience and categories                                            |

Do not invent medical requirements, HR attachments or grade management. College grade sheets require an identified owning process before inclusion; Form 137/138 admission evidence is already explicitly identified. Issued permits, acceptance letters and report exports can be added once their archival purpose is agreed. Physical envelopes are not digital document types.

Payroll computation, leave approval, applicant verification, exam/interview decisions and enrollment allocation remain in the ERPs. Password hashes, MFA secrets and ERP tokens must never enter document files or received business payloads. DMS authentication data stays in its own protected account/credential tables, not the document repository.

## 3. School repository selector: mandatory requirement

The School repository source/workspace listbox must be database-driven and permission-filtered. EMS, PMS, ES and OAS are initial records, not a permanent list of valid integrations.

Required behavior:

- A Super Admin registers a workspace/system with a stable identifier and editable display name. Registering an ERP makes it available for configuration; it does not establish a live connection or grant anyone access.
- The selector lists registered repositories the current user is authorized to inspect, including empty repositories and repositories whose ERP is disconnected. Use the configured display name, not a hard-coded label.
- “All workspaces” means all repositories within the viewer's effective permission scope. Apply identical scope to counts, search, pagination, direct links, previews, downloads and history.
- Provisioning, rotating or revoking a connector credential does not create, duplicate, rename or delete a workspace. Multiple credentials can belong to one workspace.
- Disconnection does not hide existing records or make them unavailable to otherwise authorized users. Retired repositories remain browsable as archives while new intake is disabled.
- Adding or renaming a repository updates repository filters, workspace cards/navigation, account assignments, connector choices and related filters from the same registry.
- DMS personal storage remains separate from ERP registration. Preserve writable personal workspaces and ordinary staff privacy. The latest Super Admin authority in section 14 includes DMS-wide oversight and alteration, with audited access; do not accidentally grant that visibility to Admin or Staff through a broad “All workspaces” query.
- Keep accessible empty states, preserved filter selections and a useful response to invalid/inaccessible workspace identifiers. A submitted selector value never supplies authorization.

Connection presentation must be truthful: distinguish a configured credential from observed authenticated traffic. An active token alone does not prove a system is connected or synchronized. Show last successful contact and “Not connected” where no contact has been observed; do not implement a new uptime-monitoring platform for this pass. Credential revocation may still be recorded accurately in audit history.

Current evidence: `config/dms.php` contains a static four-source array; `resources/views/admin.blade.php` uses it for repository options and has a fixed four-connection completion check. The same restriction appears in `User::sources()`, request validation, the workspace route allowlist and related views. Original source fields are three characters wide, which cannot safely represent arbitrary future system codes. Fixing only the Blade options will not satisfy this requirement.

## 4. Data and access design

Use the existing Laravel application, MySQL database, private storage and database queue. Keep Blade, Bootstrap 5.3, jQuery 3.7.1 and jQuery UI 1.14. Reuse the current document pipeline; do not introduce a second file store or microservices.

| Record                    | Planned responsibility                                                                                                                                                                                            |
| ------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Workspace/system registry | Stable UUID, school/campus, immutable unique code within scope, editable name, kind (ERP or internal), lifecycle/intake state. Seed the current ERP records once.                                                 |
| Workspace membership      | Named user, workspace and authorized responsibilities. Keep global role separate from workspace membership; do not treat connector ownership as human membership.                                                 |
| Connector identity        | Credential hash, owning workspace, permitted operations, lifecycle and last successful contact. Support independent credential rotation/revocation.                                                               |
| Document type             | Stable type code, display name, origin workspace and default classification/access policy. Begin with the agreed types; do not build a general workflow designer.                                                 |
| Document record link      | Document UUID, origin workspace, record type, stable external record ID and document type. Preserve provenance when another system receives access.                                                               |
| Document/version          | Existing private bytes, checksum, immutable version and scan state. Existing free-text reference remains for human display, not as the sole integration identity.                                                 |
| Release/share grant       | Explicit permitted recipient user/group/connector, operation, document or exact version, expiry/revocation and originating release reference. Never infer a cross-system grant from matching IDs or school alone. |
| Received business event   | Existing immutable receipt and latest snapshot. Document verification/admission state comes from authenticated source events; DMS validates the agreed schema without deciding eligibility.                       |

Avoid a broad enterprise permission framework. Implement the registry and the specific approved document exchanges first. Group/folder collaboration is a later phase with explicit inheritance rules.

Apply the latest planned hierarchy in section 14: Super Admin oversees and can alter DMS-held content across repositories within its school/campus; scoped Admin manages delegated content and Staff; Staff works with owned/shared documents. Sensitive changes by Admin require Super Admin approval; sensitive changes by Staff require scoped Admin approval. Routine uploads remain available. Human account invitations and staff affiliation must work without an ERP credential. Migration preserves current source assignments before enabling additional memberships. These are approved planning directions, not current runtime behavior.

Resolve the Clinic exception explicitly: ordinary OAS administration and general ERP oversight must not automatically expose medical bytes. Adopt record/type-specific restrictions in the shared authorization checks and agree whether Super Admin sees medical metadata only. Do not claim Clinic-only access while a broader role or connector can still download the file.

## 5. Implementation phases and owners

### Phase 0 — Resolve ownership and freeze the first exchange

Lead: Tomas. Contributors: Samuel, Mhyco and the OAS/ES/PMS/EMS owners.

1. Confirm the file inventory above, the reason for each retained document, and the office responsible for it.
2. Record DMS custody versus ERP transaction ownership in the SDD. Reconcile OAS's “central data hub” wording with DMS's received-copy model and PMS's permanent private payslip storage.
3. Define the admitted-applicant packet: exact non-medical document types, required verified version, recipients and fields. Keep score/interview-grade/medical details out of a general ES packet unless explicitly justified.
4. Agree the source event that releases the packet, the exact versions released, and behavior for replacement, admission reversal and access revocation. Recommended first behavior: retain the released version until an explicit replacement release is received; fail closed on ambiguous/stale release state.
5. Agree access exceptions, retention ownership and the cross-system disclosure rules. Do not invent a retention period or implement permanent purging in this pass.

Exit: a versioned document/exchange matrix and a resolved architecture decision. Existing SDD exclusions for folder sharing and the original API source isolation must be revised explicitly.

### Phase 1 — Introduce the dynamic workspace registry

Lead: Samuel for registry, membership and server rules. David for registry and selector UI. Tomas reviews migration/contract.

1. Add forward-only migrations for the registry and memberships. Backfill existing school/campus/source assignments and connector ownership; preserve all document UUIDs, receipts, grants, revisions and checksums.
2. Introduce registry identifiers in persistence using an additive migration. Keep legacy source codes compatible during transition; widen transitional code fields where required. Verify every source-bearing table, constraint and index rather than changing only documents.
3. Add Super Admin registration, rename and intake-disable actions with audit entries. Prevent destructive removal of referenced workspaces and code changes that would invalidate existing integration receipts.
4. Replace fixed source allowlists in request validation, route resolution, user access, controllers and views with scoped registry lookups. Arbitrary route text must resolve to an authorized registered workspace.
5. Supply one scoped workspace list to the School repository selector and related UI. Replace fixed “four sources” counts/copy with actual registry data.
6. Separate credential state from workspace existence and observed connection activity.

Exit: create a fifth synthetic ERP in administration; assign a test user and connector; use its workspace and repository filter without editing PHP/config/Blade/JS or rerunning seeds. Another school's registry entry is invisible. A disconnected workspace with documents remains accessible under existing permissions.

### Phase 2 — Establish document custody and stable ERP references

Lead: Mhyco for document intake/version behavior. Tomas coordinates ERP adapters; Samuel reviews access.

1. Add agreed document types and stable record links. Validate link identity within the authenticated connector's workspace.
2. Preserve existing idempotent intake, revision conflicts, private UUID storage and checksum verification. Treat a distinct upload of identical bytes separately from a retry of the same request.
3. Store upload bytes and enqueue ClamAV after commit. Return document/version IDs and Pending immediately; allow preview/download only after Clean and authorization/integrity checks.
4. Define read-back for current scan state and document references. Never treat a successful upload, elapsed timer or a replayed Pending receipt as proof that scanning completed.
5. Each ERP saves DMS IDs and agreed metadata, using an outbox and bounded retries. An outage must not lose an ERP transaction. An unsent file remains in a protected retry spool until DMS custody is confirmed.
6. For legacy permanent copies, reconcile per-record mappings, counts, versions and hashes before any separate approved cleanup. DMS upload receipts alone are not a backup strategy.

Exit: OAS upload, corrected version and retry produce the expected stable IDs/versions; no overwritten evidence; scan failure blocks every file-serving path; a disconnected ERP can resume safely.

### Phase 3 — Deliver the OAS-to-ES verified packet

Lead: Tomas for contract and partner coordination; Samuel for grants/access; Mhyco for version handling; David for release/access display.

1. Keep ordinary connector operations isolated to their origin workspace. Add a narrowly defined read interface for explicitly released admitted-applicant packets; do not let ES impersonate OAS or request an arbitrary source.
2. Define the packet schema: applicant external reference, minimum enrollment fields, release revision, allowed document IDs, exact version IDs, document types and source verification evidence.
3. Derive release authority from authenticated OAS state/events. Use stable event IDs and revisions so stale events cannot reopen revoked access or replace a newer release.
4. ES reads a released packet and retrieves only its allowed Clean files through server-side authenticated requests. Browser-visible ERP credentials and anonymous public links are prohibited.
5. Audit grant creation/replacement/revocation and cross-system downloads, including both origin and consuming identity. Recheck permission on each access; possessing a packet/document UUID is insufficient.
6. Document the applicant-facing read path through OAS without creating DMS student accounts. Do not expose every file behind a broad connector token merely because the request came through OAS; define and test record/operation restrictions. Medical access must not be available through the ordinary OAS/ES connector.
7. Prove application withdrawal/admission reversal, version replacement, credential revocation and grant revocation behavior. Already downloaded external copies cannot be recalled; agreements must govern their use.

Exit: ES can retrieve the agreed packet after admission, cannot read another applicant's unreleased packet, cannot modify OAS files, and cannot retrieve medical files. The exact released version is identifiable in both systems. Current implementation must not be described as supporting this exchange until these checks pass.

### Phase 4 — Add the other justified document exchanges

Lead: Tomas with each ERP owner; Mhyco and Samuel implement the agreed file/access behavior.

1. PMS: intake of finalized payslips with employee and pay-period references, immutable issued corrections and reconciliation. Keep employee self-service through PMS unless a separately authorized DMS share is designed. Do not accidentally give all employees access to the PMS repository.
2. EMS: implement only the confirmed attachment-to-recipient use case. Keep employee master-data ownership in EMS. PMS employee snapshot read-back needs its own minimal disclosure agreement; it is not permission to read all EMS records/files.
3. ES: archive only confirmed official outputs and special-case evidence. Keep enrollment decisions, schedules and sectioning in ES. Do not add grade computation based only on a professor's example.

Exit: each supported type has an identified producer, legitimate consumer, delivery trigger, classification/access rule, example file and successful reconciliation test. Unconfirmed inventories remain explicitly pending, not filled with fabricated demo requirements.

### Phase 5 — Standalone collaboration and duplicate detection

Lead: Mhyco for sharing/folders/duplicates; Samuel for organizational membership; David for interaction/UI. Tomas defines the agreed policy.

1. Enable agreed named staff/department accounts independently of connector registration. DMS personal source is not an ERP connector.
2. Implement the approved departmental review/evaluation use case with Viewer, Commenter and Editor permissions. Editor means the existing rename/new-version behavior; simultaneous in-browser content editing is outside this pass.
3. Add folder/group sharing only after documenting inheritance, explicit grants, membership removal, moves, pinned versions and revocation. A move must not silently broaden access.
4. Implement duplicate-file detection using checksum comparison within an authorized scope. Proposed first UX: warn and offer the authorized existing document, a new version when permitted, or an intentional separate record; do not automatically merge unrelated business records or expose hidden filenames/hashes.
5. Keep immutable earlier versions and scan blocking throughout sharing and duplicate decisions.

Exit: demonstrate faculty-to-head/dean collaboration; removing membership removes inherited access; unauthorized users learn nothing about hidden duplicates. This phase is an explicit extension of the original SDD, not already-delivered functionality.

### Phase 6 — Continuous integration, acceptance and handoff

Lead: Yuri. Each feature owner supplies tests; David supplies UI evidence; Tomas supplies architecture/contract documentation.

1. CI runs dependency installation, PHP formatting/encoding checks, isolated Laravel tests, frontend tests and the asset build. MySQL-specific tests run against an isolated test schema. Real ClamAV verification runs in a suitable controlled integration environment with signatures available; mocked tests are not reported as a real scan.
2. Add regression tests for dynamic registration, fifth-ERP intake, scope isolation, lifecycle/credential changes, canonical file references, retries, stale revisions, cross-system disclosure and Clinic restrictions.
3. Browser-check Super Admin, Admin and Staff on desktop/mobile: dynamic repository options, correct labels, filtering, empty/disconnected/retired states, navigation, previews and denied actions. David owns Bootstrap/jQuery UI details and professor evidence screenshots/code/rationale.
4. Run the synthetic OAS upload -> background scan -> verification event -> admission release -> ES retrieval -> replacement/revocation scenario. Compare document/version IDs and checksum at both ends; prove it reuses the stored file.
5. Update DMS SDD, API.md, openapi.json, USER-GUIDE.md, SETUP.md, OPERATIONS.md and README. Existing API routes remain compatible where possible; breaking changes require a versioned transition and partner migration instructions.
6. Confirm backup/restore of registry, memberships, grants, document links, database and private bytes as one consistent set. Keep secrets, backups and actual school files out of Git and CI logs.

Exit: reproducible standalone install, passing isolated checks, browser evidence, partner adapter checklist and a clear distinction between simulated contract tests and actual ERP connections.

## 6. Priority and merge order

1. P0: Phase 0 ownership, packet and permission decisions.
2. P1: Phase 1 registry and dynamic School repository selector.
3. P1: Phase 2 custody/references and Phase 3 first end-to-end exchange.
4. P2: Phase 4 confirmed PMS/EMS/ES exchanges.
5. P2: Phase 5 standalone/group collaboration and duplicate policy.
6. Phase 6 checks and documentation accompany every merge, then final acceptance.

Tomas publishes the agreed fields before dependent schema work. Samuel and Mhyco agree table/request changes before implementation. David consumes the agreed server-provided workspace and permission data. Yuri coordinates small pull requests, isolated CI and one shared synthetic acceptance dataset. Do not merge frontend source removal while server validation/routes still reject the newly registered systems.

## 7. Definition of integration-ready

- A new approved ERP is registered and authorized without modifying the fixed four-source list anywhere in runtime code.
- Credentials are scoped, rotated and revoked without erasing workspace history or changing human memberships.
- The ERP can upload, retry, create versions, receive real scan results, read authorized documents and reconcile receipts through the published interface.
- Cross-ERP access requires an explicit exchange grant; same-school membership is insufficient.
- Standalone staff workflows continue when no ERP is configured or reachable.
- A demonstration proves DMS value by reusing the same official document across processes, not by copying files into five separate repositories.

## 8. Source references and limits

- `DMS-SDD.pdf`: scope pages 4–5; ownership page 9; data/retention pages 13–15; integration pages 15–16; original role/access design pages 16–17.
- `PMS_Solution_Design_Document-1.pdf`: payslip delivery pages 2, 4, 10–11; local storage/custody pages 8–9 and 12.
- `SDD-PLP-Online-Admission-System-v0.1.pdf`: file types page 12; medical restrictions page 13; DMS hub and ES exchange pages 14–16; role rules page 17. This draft contains conflicting statements; do not treat every proposed endpoint as an implemented DMS capability.
- `EMS.jpg`, `ES.png`, `OAS.png`: supplied architecture diagrams. EMS/ES have no detailed file inventory in this folder.
- `AGENTS.md`, `API.md`, `openapi.json`, `FRONTEND.md`, `IMPLEMENTATION_PLAN.md`, `OPERATIONS.md`, `SETUP.md`, `USER-GUIDE.md`, `VERIFICATION.md`: implementation guidance and original roadmap. Some guides still describe legacy roles/formats/status; reconcile them against approved requirements and verified implementation.
- Current code inspected for the selector/registry gap: `config/dms.php`, `resources/views/admin.blade.php`, `resources/views/partials/stats.blade.php`, `resources/views/partials/account-form.blade.php`, `app/Models/User.php`, `app/Services/Access.php`, request validators, `routes/web.php` and source-bearing migrations.

This plan does not implement the features, assert a live partner connection, choose a retention period, change hosting or authorize deletion of existing files. No application code, credentials or database content are changed by publishing it.

## 9. All-staff accounts and institutional workspaces

Client direction, 18 October 2026: accommodate all authorized school staff, including staff whose job has no relationship to an ERP. This replaces any assumption that only EMS/PMS/ES/OAS office users can receive DMS accounts. The categories below illustrate coverage; PLP confirms its actual office list and staff roster.

### 9.1 Who uses the website

| People                                                              | Normal DMS use                                                                                                                 | Starting application role                                                               |
| ------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------- |
| Full-time and part-time faculty                                     | Read official memos, submit working documents, collaborate on assigned reports/evaluations                                     | Staff                                                                                   |
| College secretaries and administrative staff                        | Maintain assigned office documents, prepare correspondence, read institutional issuances                                       | Staff                                                                                   |
| Department heads, deans and office heads                            | Review shared reports, comment and upload authorized new versions; prepare official issuances                                  | Staff with relevant document grants; Admin only if they also administer that workspace  |
| HR, Payroll, Admission and Registrar personnel                      | Use their assigned repositories and supporting documents                                                                       | Staff, with an appointed workspace Admin where necessary                                |
| Clinic personnel                                                    | Handle authorized medical documents and otherwise participate in general staff communications                                  | Staff with explicit Clinic document access; no automatic medical access for other staff |
| Finance, procurement, library, IT and other recognized school staff | Read relevant memos and work on documents within their assigned duties                                                         | Staff; specific office document lists remain to be agreed                               |
| Assigned office/college repository administrators                   | Manage Staff membership and document organization within delegated scope                                                       | Admin                                                                                   |
| Designated DMS system administrators                                | Manage system accounts, registry and connectors; oversee and alter DMS-held documents across repositories within school/campus | Super Admin, with a writable personal workspace; see section 14                         |

Positions such as Dean, Faculty, Secretary and Clinic staff describe organizational duties. They are not additional global application roles. Use the existing three roles plus department/workspace membership and document permissions. Do not promote every department head to Super Admin or give every faculty member an administrative screen.

Authorized contractual staff can be accommodated when present on PLP's approved staff roster. External guests and vendors do not automatically qualify as staff. Public registration and student/applicant DMS accounts remain outside this plan.

### 9.2 Account lifecycle and membership

1. Account activation is invitation-only, based on verified staff eligibility. An authorized inviter proposes email, role, school/campus and workspace assignments; the relevant approval must complete before an invitation is sent. No inviter chooses the recipient's password. See section 14.4 for the full lifecycle.
2. One person has one named DMS identity, including people serving multiple departments. Application role, workspace memberships and document grants remain separate. Never use a shared office password.
3. After accepting an expiring, single-use invitation and setting their own password, Staff sign in to a staff landing page with personal, shared and permitted school documents. Ordinary staff personal routes are planned work; the existing Super Admin-only route is insufficient.
4. Admin proposes Staff invitations/access changes within delegated scope; those sensitive Admin actions require Super Admin approval. Only Super Admin authorizes Admin/Super Admin privileges. Staff cannot invite other people or grant roles.
5. Transfers and departure change memberships or suspend access without erasing document provenance, actor history or earlier versions. Invalidate affected sessions and outstanding invitation/recovery links. Institutional custodianship transfers must be explicit and audited.
6. ERP connector payloads never automatically create active human accounts or assign application roles. Trusted roster intake or bulk invitation preparation may reduce repetitive entry, but its identity-verification and approval contract is still to be agreed. Invitations are now a required planned feature; SSO remains a separate undecided integration.

### 9.3 Workspace coverage

Provide database-registered internal workspaces such as Institutional issuances and approved college/office repositories, alongside registered ERP workspaces. Actual office names and groups come from administration, not Blade lists. Personal document ownership is distinct from a shared institutional repository.

Staff should see only repositories and documents permitted to them. “All staff are accommodated” means they can have accounts and participate in relevant work; it does not mean they can inspect all school records. The user-facing School documents view must not send Staff to the administration-only route or expose system management controls.

## 10. Document scenarios and proposed classifications

These are proposed operating scenarios, not claims that all processes or document types are already implemented/approved. Classification is based on the contents and consequences of disclosure, not merely the filename, extension, document type or office name. A single document type can have multiple classifications.

| Document and example                                                               | Producer / custodian                          | Classification and audience                                                                                                           | DMS usage and reason                                                                                                                                                                                                               |
| ---------------------------------------------------------------------------------- | --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| General memo: holiday schedule or campus maintenance                               | Authorized issuing office                     | Internal; explicitly released to All active staff within the approved campus/school scope                                             | Staff open the same official document in School documents; replacement versions remain traceable. This needs no ERP.                                                                                                               |
| College memo: faculty meeting schedule                                             | College office                                | Internal; college faculty/staff group only                                                                                            | Circulate within the college; do not make it school-wide just because it is Internal.                                                                                                                                              |
| Personnel-specific disciplinary memo                                               | HR or authorized office                       | Restricted; named subject and authorized HR/decision-makers only                                                                      | Preserve the sensitive issuance without exposing it in the general memo library. Final audience requires HR confirmation.                                                                                                          |
| Draft policy, sensitive management-meeting minutes or unpublished college proposal | Owning office/college                         | Confidential; named drafting/review group                                                                                             | Review comments and submitted revisions stay with one document. An approved non-sensitive release can be a separate Internal document, with an audited relationship.                                                               |
| Approved non-sensitive policy, circular or blank office template                   | Authorized issuing office                     | Internal; designated staff audience                                                                                                   | Maintain an identifiable current release and preserve superseded copies. Business/content sign-off stays with the responsible office; the planned DMS approval flow controls official publication and access changes (section 14). |
| Departmental accomplishment report or system evaluation with internal findings     | Assigned author, department/college custodian | Confidential by default for this proposed scenario; author, assigned reviewers, head/dean                                             | Draft -> review comments -> new version -> final issued version. Share wider only after checking sensitive content.                                                                                                                |
| Individual employee performance evaluation                                         | Authorized evaluator and HR                   | Restricted; assigned evaluator, authorized HR and approved subject access                                                             | Avoid confusing an individual personnel evaluation with a general system review. Restrict attachments, search results and comments as well as file downloads.                                                                      |
| Appointment/contract attachment used by Payroll                                    | HR; exact type needs confirmation             | Restricted; authorized HR and explicit Payroll recipients                                                                             | Payroll reads the required version using a grant; other HR evidence stays inaccessible. EMS retains employee business records.                                                                                                     |
| Finalized payslip PDF                                                              | PMS / authorized Payroll custodian            | Restricted; authorized Payroll processing; employee's own copy through PMS under the agreed interface                                 | Preserve the issued artifact and corrections, not a second payroll computation. No institution-wide Staff access.                                                                                                                  |
| Applicant birth certificate, ID, Form 137/138 and agreed admission evidence        | OAS / Admission                               | Confidential baseline; stricter requirements may apply after policy review; assigned reviewers and released ES packet recipients only | Preserve resubmissions and reuse the verified exact version during enrollment. An applicant is not a DMS website account.                                                                                                          |
| Medical submission/result                                                          | Clinic                                        | Restricted; specifically authorized Clinic personnel only                                                                             | Preserve evidence and versions. Admission/ES may receive a minimum clearance/status event where agreed, not the medical file or clinical details.                                                                                  |
| Issued enrollment form/certificate or student-specific grade report                | ES / Registrar where the output is confirmed  | Confidential baseline; responsible Registrar/authorized recipient, with stricter controls for particular contents                     | Preserve an issued output. Do not introduce grade entry/computation into DMS. Applicant Forms 137/138 are separate document types.                                                                                                 |
| Public-facing announcement intended for a website                                  | Authorized communications office              | Internal copy for staff use in DMS                                                                                                    | Publish separately through the official public channel. DMS retains the controlled source/release; this does not create public DMS links or a new Public classification.                                                           |

Passwords, MFA secrets, raw connector tokens, database dumps and infrastructure backups are not ordinary school-document uploads. Backups belong in separately protected operational storage. School project/source-code submissions are not included merely because DMS accepts their file extensions.

### 10.1 Detailed memo scenario

1. A designated office author uploads `Memo-2026-014-Staff-Assembly.pdf` into the issuing office workspace, sets type Memo and classification Internal, and supplies reference, issuing office and intended audience. The audience initially remains the author/authorized office team; classification alone never circulates it.
2. DMS stores an immutable Pending version and scans automatically. The author can see status; recipients cannot preview/download blocked bytes.
3. The authorized office completes its normal business/content sign-off. The planned DMS sensitive-action flow separately approves official publication and access changes. Record issuer, memo reference, issue date and the exact version circulated as release metadata; neither approval path is implemented by this plan.
4. The Staff author requests circulation from the scoped Admin; an Admin-initiated sensitive request goes to Super Admin. Approval applies the explicit audience and exact released version atomically. The planned configured All active staff group or narrower audience must remain permission-scoped; it is not an existing feature. Staff never apply their own requested sharing changes. Issuing authority is explicitly delegated, not inferred from job title.
5. A faculty member, librarian, HR employee and college secretary sign in using their individual Staff accounts and find the memo in their permitted School documents view. None needs an ERP account or connector.
6. A correction creates version 2. Keep it private to the author/review team until the authorized issuer releases it. Audience grants point to the released version so uploading an unpublished revision cannot expose it automatically. The UI labels current issued and superseded versions clearly.
7. Recipients can read/download under their grant; they cannot edit, trash, move or reshare the issuing office's document. Issuer corrections and circulation/revocation are audited.

Required release fields are proposed new metadata, not present capabilities: document type, reference number, issuing office, issue date, released version, issuer and audience/release time. Read-receipt/acknowledgment tracking and broad notification delivery remain future modules. Sensitive-change approvals and invitation email are now planned core work; automatic self-approval is prohibited. Do not claim that reading a memo is already tracked as formal acknowledgment.

### 10.2 Detailed college collaboration scenario

An assigned CCS faculty author uploads a draft system-evaluation report as Confidential. The workspace Admin proposes Commenter grants for assigned reviewers and Editor only for named co-authors; these Admin-initiated access changes require Super Admin approval before taking effect. Reviewers comment; co-authors submit new immutable versions. The head/dean reviews through their own Staff identity. Their job title does not expose every file in CCS. The office retains the final report and grants any other college access explicitly. Personal-data-heavy supporting evidence remains separately Restricted rather than being bundled into a broadly shared report.

## 11. Classification and audience enforcement

Document type answers what a file is. Classification answers how sensitively its contents must be handled. Audience identifies exactly who may access it. Viewer/Commenter/Editor identifies permitted actions. School/campus and membership restrict organizational scope. Malware scan state answers whether the file is safe to serve. None substitutes for another.

| Classification | Proposed rule                                                                                                                                            | Sharing / change rule                                                                                                                                                  |
| -------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Internal       | Available only to authenticated, authorized staff/approved integration recipients in the specified audience; not automatically visible to every employee | Authorized issuer/custodian can circulate to an approved broad staff group. A private Internal draft remains private.                                                  |
| Confidential   | Limited to the assigned office/project group or named recipients with a stated work purpose                                                              | No automatic All staff release; cross-office/cross-ERP disclosure needs an explicit grant.                                                                             |
| Restricted     | Limited to named authorized recipients or a narrowly maintained specialist group such as Clinic/Payroll                                                  | No broad school/college-folder inheritance or generic source-wide connector bypass. Mandatory specialist restrictions take precedence over ordinary office membership. |

Enforcement requirements:

- Apply the same effective access rules to repository listings, filters, counts, search, comments, activity, preview, download, versions, API responses and exports. Protect identifying metadata where it is sensitive; do not leak hidden record titles through duplicate checks.
- Preserve the three existing classification values. Configure document-type minimum/default policies; do not invent a fourth Public class or use an editable label as the sole security control.
- Add explicit audience/release grants; do not rely on the current broad source-based Admin/connector reads for sensitive document types. Medical/Payroll restrictions and Super Admin oversight require the resolution already noted in section 4.
- Owners/authorized custodians can propose/edit classification within type policy. Editor permission alone does not permit classification or audience changes. Downgrades and wider circulation of protected types require an authorized custodian and an audited reason; users cannot mark a medical file Internal to bypass access.
- On increased restriction, atomically remove invalid audience grants and prevent future reads; do not merely change a badge. On reduced restriction, retain the current audience until an explicit authorized circulation action. Historical versions keep their protective requirements; releasing a sanitized copy must not reveal earlier sensitive content.
- For current memberships, All staff means active approved staff within the issuer's allowed scope. Decide explicitly whether a new group member sees historical issuances; recommended default is all retained released memos for their scope. Deactivation removes online access immediately.
- Revoking one group grant does not remove an independent legitimate explicit grant; effective access calculation must explain remaining access. Mandatory restrictions cannot be overridden by a permissive folder grant.
- Use existing scope, grant, checksum and scan protections consistently. A Clean file remains inaccessible without authorization; an authorized user still cannot retrieve a Pending/Failed/Quarantined file.

These are planned policy rules. Current classification metadata does not implement this matrix by itself. Retention/disposal schedules, cross-campus disclosure and Super Admin handling of specialist medical records remain explicit decisions; no legal retention period is inferred here.

## 12. Updated delivery priorities and acceptance scenarios

This section updates sections 5–6: all-staff accounts and everyday institutional documents are core P1 requirements, not features to defer until after four ERP integrations. Folder/group inheritance can remain a later implementation step, but it must not postpone named-staff access and a minimal memo circulation flow.

| Delivery                                                                                 | Owner coordination                                   | Acceptance evidence                                                                                                                                          |
| ---------------------------------------------------------------------------------------- | ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| P0: document/audience/classification matrix, issuing authority and specialist exceptions | Tomas; office owners; Samuel                         | Confirm the memo and admission packet scenarios plus minimum policies; distinguish approved types from proposals                                             |
| P1: dynamic registry and independent all-staff accounts                                  | Samuel; David for UI                                 | Create a librarian/faculty account with no ERP reference, assign an internal workspace, sign in and access only permitted documents                          |
| P1: institutional memos and basic staff sharing                                          | Mhyco; Samuel for policy; David for UI               | Release one Clean memo to the authorized active-staff audience; publish a correction without exposing an unreleased revision; retain earlier issued versions |
| P1: effective classification access                                                      | Samuel; Mhyco for grants/version handling            | Staff cannot discover/download an HR disciplinary memo or Clinic file; changing a badge or guessing a UUID cannot bypass controls                            |
| P1: custody and OAS-to-ES exchange                                                       | Tomas with partner teams; Mhyco and Samuel           | Reuse the exact released applicant-document version without another upload; protect medical files and unreleased applicants                                  |
| P2: confirmed further ERP document types, folder collaboration and duplicate detection   | Tomas, Mhyco and Samuel; David for interactions      | Each exchange has a purpose; membership/move/revocation tests pass; duplicates do not disclose hidden files                                                  |
| Every merge: CI, browser checks, report/screenshots and guide updates                    | Yuri coordinates; all owners supply feature evidence | Show distinct role screens, responsive memo/repository views, dynamic selector and standalone use with no ERP reachable                                      |

Use synthetic files in demonstrations. Additional acceptance cases: one staff member belongs to two departments without a second login; college-only memo stays out of another college's search; suspended staff lose access; an Admin cannot create an Admin; a Dean's Staff account cannot manage accounts; a new registered internal workspace and a fifth ERP appear in authorized selectors without code edits; token revocation leaves released documents available to independently authorized staff.

Implementation gaps to cover explicitly: ordinary Staff personal routes, accounts without mandatory ERP source, internal workspace registration, memo type/release metadata, document-type minimum policies, version-pinned circulation, dynamic staff audiences and effective-access checks. The existing generic upload/classification selector is only a starting point.

## 13. Revised Figma file-detail design and Tomas handoff — 18 October 2026

### 13.1 Identity, ownership and status

The requesting team member is **Tomas**, responsible for architecture, document definitions, classification/access requirements and ERP coordination. David remains responsible for Bootstrap/jQuery UI implementation. Samuel owns account/access enforcement, Mhyco owns document/version/sharing behavior, and Yuri coordinates CI and acceptance evidence. This design update does not transfer all frontend implementation work to Tomas.

The four exact existing frames below were redesigned in place. Their IDs are preserved, so old links to these frames remain valid. They replace the earlier oversized button tabs, space-aligned metadata strings, compressed version/share text and sparse preview composition.

| View            | Authoritative revised Figma frame                                                                      | Implementation target                                                                         |
| --------------- | ------------------------------------------------------------------------------------------------------ | --------------------------------------------------------------------------------------------- |
| Details         | [06 Detail / Details](https://www.figma.com/design/n44HHt77BnanifVreeSP7S/dms?node-id=25-2306)         | File identity, two-column metadata, Access & security card and latest activity                |
| Version history | [06 Detail / Version history](https://www.figma.com/design/n44HHt77BnanifVreeSP7S/dms?node-id=25-2337) | Structured version table with uploader, checksum, actual security state and permitted actions |
| Preview         | [06 Detail / Preview](https://www.figma.com/design/n44HHt77BnanifVreeSP7S/dms?node-id=25-2360)         | Large selected-version preview with page and zoom controls                                    |
| Shares          | [06 Detail / Shares](https://www.figma.com/design/n44HHt77BnanifVreeSP7S/dms?node-id=25-2385)          | Named recipients, permission, version scope, grant expiry, status and authorized actions      |

Figma status: all four frames updated and visually reviewed. Inactive tabs link to their matching frames. Other action buttons are design examples, not evidence that a backend action exists. Original frames are recoverable in the hidden `Archive / 06 Detail before redesign / 2026-10-18` container. Shared header, metadata-field, version-row and grant-row patterns are editable components outside the four review frames under `06 Detail / Local patterns`.

The application implemented the earlier Figma design in the previous pass. **This revised Figma design is not yet applied to Blade/CSS.** This pass changes Figma and this plan only; it does not change application role permissions or database records.

### 13.2 Visual and interaction contract for David

1. Use the existing Blade document route, Bootstrap 5.3, jQuery 3.7.1 and jQuery UI 1.14. Retain real data and existing protected document actions. No framework replacement or additional file store is required.
2. Use the existing DMS green/yellow variables and Inter typography. The revised design uses 28px filename headings, 18px section headings, 16px body/actions and 14px secondary labels with clear contrast. Preserve visible keyboard focus and readable hover states.
3. Treat the 1100 x 720 Figma frames as desktop compositions, not a fixed browser canvas. Use a maximum-width surface with natural height; allow content to grow for long names, extra recipients, error messages and additional versions. Avoid clipping content to match an artboard height.
4. Keep file identity and the primary Download action in the shared header. Show actual reference, category, size, origin workspace, classification and selected accessible version. Do not hard-code Enrollment System or the sample student/owner into templates.
5. Replace the four equal oversized buttons with the revised compact tab navigation. Keep accessible jQuery UI tabs, keyboard operation and selected-tab restoration after a scan refresh. Close returns to the correct folder/shared listing without a dead end.
6. Details: use semantic label/value fields, not strings padded with spaces. Separate general metadata from Access & security and activity. Keep the full checksum available through an accessible disclosure; a truncated display is not the stored value. Keep comments and complete activity reachable without inventing another backend module.
7. Version history: highlight the current accessible version and display actual creation time, uploader identity where reliably available, checksum, scan state and allowed preview/download actions. Quarantined/Pending/Failed versions remain visibly blocked. Earlier versions are immutable; no overwrite action is introduced.
8. Preview: render the actual selected Clean file using the current safe preview infrastructure. Derive page count and zoom from the viewer, not the two-page sample in Figma. Preserve image/text/CSV/JSON preview behavior and a helpful download-only message for unsupported formats. The Figma preview text is an editable illustrative sample, not an asset to embed in the app.
9. Shares: show Owner separately from Viewer/Commenter/Editor grants; these are document relationships, not extra application roles. Show actual Active/Expired/Revoked states, exact pinned-version scope and access expiry. Revoke appears only when allowed. Renew should open the existing Manage access flow with the recipient preselected, subject to server authorization; do not create an unguarded renewal endpoint.
10. Wire contextual actions only when backed by a real permitted operation. Keep the More menu limited to existing authorized actions; place trash inside that menu and retain its recovery/confirmation behavior. DMS/ERP identity, classification, file safety and access grants remain separate concepts.
11. On narrower screens, stack metadata and security/activity cards, allow tabs to wrap cleanly, and keep tables horizontally scrollable within their own region. Preserve readable text and touch controls. Do not cause horizontal scrolling of the whole page or shrink the entire desktop design to fit a phone.

### 13.3 Tomas's concrete architecture deliverables

- [ ] Confirm the document-detail field contract: filename, title, subject, document type/category, reference, source workspace, folder, classification, owner/custodian, created/modified timestamps, immutable version, size, checksum and scan status. Mark each as persisted, derived or not yet available.
- [ ] Confirm field meaning for **memos and departmental files**, not only student/ERP examples: subject is not necessarily a student name, source may be an internal office workspace, and reference may be an official memo number. Use examples across both standalone and integrated workflows.
- [ ] Distinguish owner/custodian from uploader, issuer and source connector. The current view does not provide a reliable uploader field on every version. Agree either authoritative actor attribution from existing audit evidence or a forward migration before David displays it; never fill the uploader column with the owner by assumption.
- [ ] Define selected-version behavior for pinned shares, current versions and issued memo releases. A viewer pinned to version 2 must not see metadata, checksums, previews or download actions that expose version 3.
- [ ] Confirm classification defaults/minimum rules and the audience matrix from sections 10–11. A Confidential badge is sensitivity information, not proof of permission; Clinic-only files and payroll restrictions require actual server rules.
- [ ] Preserve the distinction between **share expiry** and document retention/expiration. The current app has sharing-grant expiry, not a document-expiration policy. The redesigned Details view therefore does not invent a document expiry date; expiry belongs in Shares (and may be described as the current viewer's access expiry).
- [ ] Implement the latest confirmed Super Admin direction from section 14: DMS-wide document oversight and alteration within school/campus, including a writable personal workspace. The current code still enforces the older read-only ERP rule. Record specialist medical handling explicitly before release; Figma controls do not implement authorization.
- [ ] Confirm human account role versus office/group membership versus connector identity. All authorized staff can be accommodated independently of an ERP, while document access remains scoped. Feed the document header, selectors and access summary from the dynamic registry rather than a fixed four-system list.
- [ ] Align OAS-to-ES packet references and any approved EMS/PMS exchanges with document/version IDs and explicit disclosure grants. Changing presentation must not turn DMS into a writer to ERP business records.
- [ ] Publish the field/access contract and unresolved decisions for David, Samuel and Mhyco; update the SDD and agreed interface schemas when a decision changes the original design. Coordinate through the existing team process; this plan does not send messages to teammates.

### 13.4 Verification and completion criteria

Figma evidence: the four frames contain editable text, vector icons, auto-layout containers and component instances; no screenshot of a complete UI is used as a design layer. Inter was verified on all text. The compact artboards, status/icon contrast and all four content states were visually reviewed. Synthetic names, dates, checksums and counts are examples only.

Before calling the revised application implementation complete:

- [ ] David matches the four revised Figma views at desktop size and verifies phone/tablet layouts, long filenames and expanded content.
- [ ] Samuel verifies direct request authorization for Super Admin, scoped Admin, Staff owner, Viewer, Commenter and Editor; hiding a control is insufficient.
- [ ] Mhyco verifies active/expired/revoked and pinned grants, older-version selection, unsupported previews, blocked scans, trash/restore and correct uploader attribution or a truthful unavailable state.
- [ ] Yuri runs the isolated application/frontend checks and captures screenshots plus jQuery/Bootstrap code evidence for the professor's report.
- [ ] Tomas checks the implementation against the document-purpose, account, classification and ERP ownership contract. Report unresolved architecture decisions explicitly rather than allowing sample Figma data to become product policy.

## 14. Latest role, approval and account decisions — planning only

### 14.1 Decision status and scope

Tomas confirmed this direction on 18 October 2026 and explicitly requested **planning, not implementation**. A previous implementation attempt produced unapplied drafts outside the DMS repository. They are incomplete, untested and must not be copied into the app or migrated merely to resume this plan. No application files, account records or database schema are changed by this planning commit. Existing unrelated frontend changes remain outside this commit.

Confirmed decisions:

- Super Admin oversees everything and can alter DMS-held documents and settings within the authorized school/campus. This replaces read-only ERP oversight; its own personal workspace stays writable.
- Admin has delegated workspace permissions. Admin-initiated sensitive actions require Super Admin approval.
- DMS Staff and ERP Staff use the Staff application role. Sensitive Staff requests require an authorized Admin for the target workspace.
- Approval applies to **sharing, classification changes, trash/restore, account/access changes and official publication**. Routine uploads remain available and continue through automatic background ClamAV scanning.
- Accounts must use invitations in this staff-only environment. No public/student registration and no administrator-selected initial password.
- Human account identity, application role, workspace membership and ERP connector identity are separate. An ERP head/administrator does not automatically become DMS Super Admin.

Scope means DMS-held documents and metadata, not permission to edit source ERP databases or run their business decisions. Super Admin authority does not bypass immutable versions, audit history, school/campus boundaries, checksum validation or malware-scan blocking. Medical specialist restrictions still need a documented release decision; do not claim Clinic-only access and unrestricted Super Admin medical access simultaneously.

### 14.2 Planned permission matrix

| Action                                             | Super Admin                                                                | Scoped Admin                                                   | Staff, including ERP personnel                                                  |
| -------------------------------------------------- | -------------------------------------------------------------------------- | -------------------------------------------------------------- | ------------------------------------------------------------------------------- |
| View permitted documents                           | All DMS repositories within school/campus, with recorded specialist policy | Delegated repositories and explicit grants                     | Owned, assigned or explicitly shared documents                                  |
| Routine upload/new version                         | Direct within authorized DMS scope                                         | Direct within delegated editing scope                          | Direct for owned/assigned documents or valid Editor grant                       |
| Routine rename/move                                | Direct under existing document rules                                       | Direct within delegated scope                                  | Only when existing ownership/grant rules allow; Editor alone cannot move        |
| Sharing grant/revoke                               | Apply directly and audit                                                   | Request Super Admin approval                                   | Owner/custodian may request scoped Admin approval; no direct access change      |
| Classification change                              | Apply directly subject to minimum policy                                   | Request Super Admin approval                                   | Owner/custodian may request scoped Admin approval; Editor alone is insufficient |
| Trash/restore                                      | Apply directly, retaining versions                                         | Request Super Admin approval                                   | Authorized owner/custodian requests scoped Admin approval                       |
| Official publication                               | Apply directly to an eligible Clean version                                | Request Super Admin approval                                   | Authorized author/custodian requests scoped Admin approval                      |
| Invite/manage Staff                                | Direct audited invitation/access administration                            | Propose within delegated scope; Super Admin approves           | Cannot create/manage others; may request changes to own access                  |
| Grant/change Admin or Super Admin privilege        | Super Admin only                                                           | Cannot grant through Staff management or approve an escalation | Cannot grant or approve an escalation                                           |
| Registry, connectors and system-wide configuration | Super Admin only                                                           | Cannot alter system integration/configuration                  | Cannot alter system integration/configuration                                   |
| Own profile/password/security settings             | Own settings directly                                                      | Own settings directly                                          | Own settings directly                                                           |
| Audit and approval screens                         | Full scoped system audit; Admin requests and eligible escalation           | Scoped audit; assigned Staff requests and own request history  | Own request history and permitted document activity                             |

Approvals do not expand the requester's legitimate scope. A user who cannot act on a document cannot obtain access by submitting an approval request. Reading, previewing, downloading, comments and stars remain subject to their existing permissions, not the sensitive-change approval queue. Initial upload classification is selected before upload; changing an existing classification requires approval. Uploading a new version never silently publishes it or changes an exact-version release grant.

### 14.3 Sensitive-change flow

1. Validate current role, active status, school/campus, membership, document relationship, type minimum policy and action inputs on the server.
2. For Admin/Staff, record a Pending request with requester, target, exact proposed change, reason, expected revision/state, timestamp and assigned approval scope. Keep live data unchanged. Do not store passwords, invitation tokens or uploaded file bytes in the approval payload.
3. Show the appropriate reviewer the current and proposed values, requester and scope. Staff requests route to a scoped Admin; Admin requests route to Super Admin. Never permit self-approval or allow an Admin to approve another Admin's request or grant higher application roles.
4. Recheck both parties' current authority and the target's revision/state at decision time. A changed/deactivated requester, removed membership, stale document or expired grant must not be applied using old permissions.
5. Approval applies the exact authorized change atomically and once, records requester and reviewer, advances relevant state/revision, and marks Approved. Rejection records a reason without changing live data. Request history must distinguish Pending, Approved and Rejected; stale requests require a fresh proposal rather than silent overwrite.
6. Show clear pending/success/rejection feedback in the existing UI. A submitted request must not make a file disappear, display a changed classification, send an invitation or announce an official release before approval.
7. Publication identifies the exact Clean version and authorized audience. It does not create a public link, remove classification restrictions or replace the originating office's business/content sign-off. Later uploads remain unreleased until an explicit approved replacement.

Before implementation, confirm the fallback when no scoped Admin is available, coverage for an approver's absence and any cancellation/reassignment rules. Suggested fallback: explicitly assign a Super Admin reviewer; never auto-approve on timeout. These fallback details are proposals, not a confirmed workflow.

### 14.4 Invitation, login, recovery and account-settings phases

| Phase                                    | Planned behavior                                                                                                                                                                                                     | Acceptance evidence                                                                                                                                                                          |
| ---------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Staff eligibility and invitation request | Verify the staff identity/roster, intended application role and workspace membership. Super Admin invites directly; Admin proposes only Staff within delegated scope for Super Admin approval.                       | Students, anonymous registrants, arbitrary connector payloads and unauthorized inviters cannot provision accounts.                                                                           |
| Invitation approval and delivery         | Only after required approval, send an invitation to the verified recipient email (personal or institutional). Show Invited/Pending activation separately from Active and Suspended. Record who invited and approved. | No active login or welcome email is created merely by a pending request. Delivery failure is visible and retryable.                                                                          |
| First-time activation                    | An expiring, single-use link lets the recipient set and confirm their own strong DMS password. Store only token hashes; protect acceptance with validation, throttling and CSRF.                                     | Invalid, expired, used, superseded or suspended-account invitations fail safely; accepted invitations cannot be replayed.                                                                    |
| Normal sign-in                           | Individual DMS email/password; normalized email, generic failure message, throttling and session renewal. Check active/activated state on sign-in and subsequent requests.                                           | Invited-but-unactivated and suspended accounts cannot sign in. Role/workspace determine the landing page and available controls.                                                             |
| Staff landing page                       | Personal documents, Shared with me, permitted School documents and own request history. Staff do not receive Super Admin navigation.                                                                                 | Faculty, librarian, secretary and ERP Staff can participate with one DMS identity and no configured ERP token.                                                                               |
| Admin/Super Admin landing pages          | Admin sees delegated content/Staff and Staff approvals. Super Admin sees system-wide administration, Admin approvals, registry/connectors and its own workspace.                                                     | Direct URLs and forged requests enforce the same limits as the visible UI.                                                                                                                   |
| My account                               | Own profile and password, current role/memberships, account/security status and a way to request access changes. Role/scope/security identity changes cannot be self-assigned.                                       | All roles can reach settings, change their own password using current-password validation and sign out. Email changes require address verification, not an unverified login-identity switch. |
| Forgotten password                       | Generic response; time-limited single-use recovery to the verified address. Authorized administrators may initiate a reset link, subject to the hierarchy, without viewing or choosing the new password.             | No account enumeration, token leakage or reusable reset link; a reset invalidates affected sessions.                                                                                         |
| Transfer/suspension/departure            | Approved membership/status change, session and invitation invalidation, explicit institutional custodianship handover. Preserve provenance, audit and versions.                                                      | A previously signed-in suspended user loses access; old pending requests cannot restore removed privileges.                                                                                  |

Mandatory invitation delivery needs a working configured mail service and a trusted application URL/HTTPS deployment. Do not describe log-mail output as a sent invitation or write raw activation/recovery links into logs or Git. Exact invitation lifetime, resend rules, recovery support and sender identity remain settings/policy decisions, not invented production values.

Tomas rejected repetitive manual account addition as the intended onboarding experience. Invitations are confirmed; **how invitation candidates are supplied at scale is still open**. Evaluate approved staff-roster/bulk invitation preparation or authenticated ERP invitation requests against one deduplicated DMS identity. Such input may propose eligible Staff but must never automatically grant Admin/Super Admin or bypass verification/approval. Keep standalone invitations available when an ERP is disconnected.

### 14.5 ERP credentials and personnel mapping

- DMS must not collect, display or reuse an employee's ERP password. Staff set a separate DMS password through invitation unless a separately approved SSO design is later introduced.
- A connector uses its own scoped server-side credential for the integration API, independent of human sessions. ERP administrators keep that secret out of browser code, documents, staff profiles and received payloads.
- An ERP administrator/high-ranking staff member receives a named DMS identity with the application role and workspace responsibilities approved for that person. Organizational title is not a permission bypass.
- Connection setup, token rotation/revocation and observed contact do not create human accounts or erase their independent DMS memberships. Revoking a connector is not the same action as suspending staff.
- SSO/identity federation, MFA and automated staff-directory synchronization remain decisions requiring explicit interface/security contracts. Do not promise that existing ERP credentials already sign users into DMS.

## 15. Resume checklist and continuous-integration handoff

**Current instruction: continue planning only until Tomas explicitly authorizes implementation.** Keep the existing Laravel/Blade/Bootstrap/jQuery stack. Do not run new migrations, seeders, password resets or apply staged role drafts to resume this document.

| Owner  | Next planning deliverable                                                                                                                                        | Implementation handoff / proof when authorized                                                                                                                                          |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Tomas  | Finalize document/audience matrix; Super Admin reach and specialist exceptions; approval actions/reviewer assignment; staff invitation and ERP identity contract | Resolve missing-admin fallback, verified roster source, publication authority and mail delivery policy; update the SDD/interface contract                                               |
| David  | Revised Figma details from section 13; distinct Staff/Admin/Super Admin screens; invitation/activation/login/settings and pending-request states                 | Accessible Bootstrap/jQuery UI; responsive screens; no false success before approval; professor screenshots and code rationale                                                          |
| Samuel | Role/membership policies, approval transition rules and complete account lifecycle                                                                               | Forward migrations; atomic/revision-safe approval execution; invitation/recovery validation; scoped UI/server authorization and regression tests                                        |
| Mhyco  | Document/version/grant behavior under approvals; publication version pinning; classifications and authorized duplicate handling                                  | Preserve private storage, immutable versions and async scans; pending changes do not broaden access or expose unreleased versions                                                       |
| Yuri   | Test matrix, isolated CI and documented acceptance dataset                                                                                                       | Test role boundaries, no self-approval/escalation, stale/double decisions, revoked access, invitation/reset replay, role-specific browser views and real scanner environment separately |

Required integrated demonstrations before completion:

- A Staff author uploads normally, scanning runs in the background, and unsafe bytes stay blocked for every role.
- Staff sharing/classification/trash/publication requests leave live data unchanged; a scoped Admin approves or rejects. Admin-initiated requests require Super Admin.
- Super Admin can alter DMS-held ERP documents within scope; old read-only runtime checks and copy are updated together, while cross-school/campus reads remain denied.
- A named invited Staff member activates and signs in without an ERP connection; duplicates and privileged ERP claims cannot create a second identity or elevate access.
- Admin invitation request -> Super Admin approval -> actual email delivery -> first-time activation -> correct role landing -> account settings -> password recovery -> suspension all work as one lifecycle.
- A new registered workspace appears throughout authorized selectors and assignments without hard-coded source edits. Existing disconnected workspaces remain usable.
- A memo correction stays private until its exact version is approved for release; OAS-to-ES retrieval uses explicit grants and the same preserved document/version IDs.

Completion claims must separate planning decisions, implemented runtime behavior, automated tests, browser demonstrations and actual partner/SMTP/ClamAV verification. This commit preserves discussion context; it does not certify those features as delivered.

## 16. Confirmed invitation hierarchy and scope clarification — 18 October 2026

This section records the latest discussion and takes precedence over conflicting earlier assumptions. This is an implementation-plan update only, not authorization to implement application features.

### 16.1 Current integration scope and medical references

The confirmed integration scope is EMS, PMS, ES and OAS. The design remains open to future approved integrations; extensibility does not make additional systems current requirements.

A separate Clinic integration and its Super Admin access policy are not confirmed. Earlier medical references in this plan were attributed to an OAS SDD; that underlying source has not been independently verified in the current review. Treat those passages, inventory rows and Clinic acceptance examples as conditional proposals, not confirmed scope or an established Clinic-only policy. First verify with the OAS owner whether medical files enter DMS at all. If confirmed, agree their inventory and access rules before intake; do not infer unrestricted access or a particular specialist exception from this clarification.

The agreed general Super Admin authority within school/campus remains as stated in section 14. Medical-specific exceptions remain unresolved only if those records are confirmed for inclusion.

### 16.2 Hierarchical invitations with an audit trail

Confirmed invitation hierarchy: **Super Admin (SA) -> Admin (A) -> Staff / ERP Representative (S/ERP Rep)**.

| Inviter / recipient                 | Planned authority                                                                                                                                            |
| ----------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Super Admin -> Admin                | Super Admin invites a named Admin and assigns its delegated workspace/scope.                                                                                 |
| Admin -> Staff / ERP Representative | Admin proposes colleagues within its delegated workspace; Super Admin approval is required before sending under the existing account/access approval policy. |
| Staff / ERP Representative          | Accepts its own invitation; cannot invite others, assign roles or delegate invitation authority.                                                             |

ERP Representative is an affiliation/responsibility under the Staff application role, not a fourth global role. An ERP high-privilege user can act as a DMS inviter only after Super Admin explicitly assigns that person the appropriate DMS Admin authority. ERP rank, connector ownership or a claimed role in an incoming payload does not confer that authority automatically.

Use an individual, expiring, single-use invitation link bound to the intended recipient and approved role/membership. Do not use a reusable public or office-wide registration link. Recipients set their own DMS password. Preserve the no-self-approval rule and recheck inviter/approver authority before applying the approved assignment.

Audit the invitation request, approval/rejection, authorized inviter and approver, intended recipient, role, school/campus and workspace assignment, delivery attempt/result and activation. Record lifecycle changes such as resend, revocation and expiry without storing raw invitation tokens or passwords in audit entries. Record actual state truthfully: queued or SMTP-accepted mail is not proof of recipient delivery or activation.

### 16.3 Candidate sources and email addresses

There is no school-wide managed email domain. Invitations must accommodate verified personal as well as institutional addresses. Address ownership verification does not by itself establish school staff eligibility; an authorized inviter must confirm the person's staff status.

CSV/Excel bulk invitation preparation is under consideration, alongside individual invitations. It is not yet a selected import implementation. A proposed workflow is import -> validation/deduplication -> assignment review -> required approval -> queued invitations -> per-recipient results. Each recipient remains subject to the same hierarchy and activation controls. Final columns, accepted file formats and bulk approval behavior remain to be agreed.

Existing accounts in the four ERPs are being considered as evidence of staff eligibility. This is not yet an automatic-access or shared-login decision. Confirm which ERP account types identify active staff, their stable identifiers and trustworthy status source. Applicant/student accounts do not qualify under the staff-only scope. ERP-assisted candidate submission must use an agreed authenticated interface, not arbitrary received-history payloads.

Keep one named DMS identity per person across ERP affiliations; add approved memberships rather than duplicate accounts. Identity matching, conflicting email/identifier handling and transfer/departure updates need an explicit contract. Do not automatically merge accounts solely on a name match. Maintain standalone invitations for eligible staff without ERP accounts or while an ERP is disconnected.

### 16.4 Remaining decisions and operational dependencies

- No-scoped-Admin fallback, absence coverage and reassignment: TBD; do not enable timeout-based automatic approval.
- Final document inventories, classification minimums and publication authority: TBD.
- CSV/Excel import choice, verified roster source, ERP eligibility interface and identity matching: under consideration.
- Invitation expiration/resend values and recovery rules: system policy/configuration decisions still to be defined.
- Real mail delivery: requires configured SMTP/mail service and a functioning queue worker, with visible failures and retries; do not report log-mail output as sent email.
- SSO and reuse of ERP sign-in: separate future decision; invitations do not require a common email domain or shared ERP passwords.

### 16.5 Planning handoff and acceptance additions

- Tomas: confirm the invitation hierarchy contract and ERP staff eligibility sources; verify any OAS medical-file requirement before treating Clinic rules as scope.
- Samuel: specify scoped invitation authorization, approval transitions, recipient binding, identity deduplication and audit events.
- David: design SA Admin-invite, Admin colleague-invite, pending approval, activation and delivery-result screens.
- Yuri: include negative cases for Staff inviting, Admin granting Admin, out-of-workspace invitations, replayed links, duplicate identities and mail failures.
- Demonstrate SA invites Admin -> Admin activates -> Admin proposes Staff/ERP Rep -> SA approves -> individual invitation -> recipient activation, with a traceable audit trail.
- Demonstrate that personal email addresses are accepted, pending invitations do not grant access, and an ERP role claim cannot elevate DMS privileges.

External research references discussed (Microsoft CSV invitations, OWASP authorization, OpenID Connect, SCIM, NIST federation and NPC privacy guidance) are background options, not adopted dependencies or approved project requirements. The ERP SDDs and architecture images remain project evidence to verify, not proof that provisioning or SSO already exists.

This update changes documentation only. No role, invitation, SMTP, medical-access or ERP provisioning feature is delivered by this commit.
