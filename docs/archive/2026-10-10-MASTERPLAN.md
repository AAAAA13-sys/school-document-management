# School Document Management System master plan

Version 1.0 | 10 October 2026 | Proposed architecture accepted; implementation remains in progress.

## 1. Outcome

Deliver a school DMS that students and employees can use independently to store, organize, retrieve and share files. Authorized office staff manage scoped institutional records. The DMS also preserves agreed evidence delivered by Employee Management, Online Admission, Enrollment and Payroll through authenticated APIs.

The intended result is a maintainable production deployment with clear ownership, dependable history and recoverable data. The current application is an implemented baseline, not a certified production service. An architecture diagram, passing local tests, and a working development server are different kinds of evidence.

## 2. Approved decisions

| Decision | Rule |
|---|---|
| Final architectural reference | The visible diagram in `docs/ARCHITECTURE.png`, supplied as `ARCHITECTURE.drawio.png`. Its visible system-context page is exported to `docs/ARCHITECTURE.drawio` and `.xml`. |
| Deployment | One PHP/Laravel application; four logical responsibilities, not four servers or microservices. |
| Database ownership | Five standalone operational databases. DMS owns its database and central history; other groups integrate through APIs. |
| Stack | PHP 8.2, Laravel 12, Eloquent/Blade, Bootstrap 5.3 CSS, jQuery 3.7, jQuery UI 1.14, MySQL/InnoDB. |
| Business decisions | Admission, enrollment, employment and payroll decisions belong to users/source systems. No DMS approval/rejection workflow. |
| Scanning | Removed by explicit user decision, reconfirmed 10 October 2026. No ClamAV, quarantine or scanner prerequisite. Preserve validation and integrity checks. |
| File availability | Valid authorized uploads are available immediately. The legacy `scan` field may remain for compatibility; it is not an active pipeline. |
| Names | Filename and display-name uniqueness per owner workspace, case-insensitive, including Trash. Different owners may reuse names. |
| Design | Institution-neutral green/yellow interface; Drive-inspired organization with a student-first experience. |
| Integration readiness | A versioned API baseline, not plug-and-play integration with unknown source contracts. |

The PNG includes hidden editable data for older detailed pages. Those pages contain obsolete scanning labels and are not adopted as new requirements. The visible system-context diagram and the decisions above are the accepted reference. Older copies remain in `docs/archive/` for traceability.

## 3. System responsibilities

| System/group | Owns | Delivers to DMS | Must not delegate to DMS |
|---|---|---|---|
| Employee Management | Employee identity and employment records | Agreed employee snapshots and supporting documents | Employment approval, personnel calculations, password replication |
| Online Admission | Applicants, applications and admission decisions | Application evidence and agreed record revisions | Admission eligibility or approval |
| DMS | Local accounts/access, documents/versions, grants/comments, received history, audit and receipts | Authorized file access and outgoing document events | Authority over another group's business database |
| Enrollment | Enrollment records and academic registration decisions | Agreed student/enrollment snapshots and documents | Registration validation or enrollment approval |
| Payroll Management | Payroll calculations and restricted employee pay records | Agreed payroll evidence and document references | Salary/tax calculation or payroll approval |

“History of everything” means durable history of the agreed records actually delivered. It does not mean copying every table, every personal field, or every secret. Source identity mapping requires an agreed contract; names and email addresses are not sufficient universal identifiers.

## 4. Four logical tiers

| Tier | Inputs and outputs | Implementation boundary |
|---|---|---|
| 1. Presentation | User actions, rendered pages, accessible feedback | Blade views and locally served Bootstrap/jQuery/jQuery UI assets; no direct database writes. |
| 2. Application | Authorized commands, validated results, transactions | Controllers and shared `DocumentService`; enforces ownership, scope, revisions, versions and audit. |
| 3. Integration | Authenticated `/api/v1` requests, receipts, scoped reads | Source-bound bearer tokens, contracts, rate limits, replay/conflict behavior and cursor feeds. Uses application rules. |
| 4. Persistence | Metadata/history transactions and private file bytes | DMS MySQL database and `storage/app/private/documents/<version UUID>`. |

Presentation and integration both enter the application tier. The application uses persistence. External applications do not bypass application authorization to write DMS tables. This is Laravel MVC with a shared service, not a claim of fully isolated Clean Architecture.

## 5. Roles and audience permissions

| Role | Normal workspace | Institutional access |
|---|---|---|
| Student | Own Admission/Enrollment files and folders; explicit incoming shares | No institutional history, audit, integration feed or accounts |
| Teacher / Employee | Own Employee Management files/folders; explicit incoming shares | No HR/payroll authority from personal role |
| Admin | Personal workspace plus school repository | All four sources within school/campus; accounts, audit and history |
| Registrar | Source-scoped office records | Admission and Enrollment history |
| HR | Source-scoped office records | Employee Management history |
| Payroll | Source-scoped office records | Payroll Management history |

Every role remains school/campus scoped. Named shares can grant document-specific access beyond a recipient's ordinary source scope, within that boundary. Viewer reads/downloads; commenter also comments; editor also renames/uploads versions. Grants expire within 30 days, are revocable, and pin an exact version. They do not grant move, Trash, reshare or source-wide access. Folder-level sharing and browser editing of document contents are outside the current implementation.

## 6. Data domains and invariants

| Domain | Main tables | Invariant |
|---|---|---|
| Identity | `users`, `sessions` | Active local identity, role, school and campus; source payloads cannot provision accounts. |
| Documents | `documents`, `document_versions` | Additive version content, UUID paths, checksums, unique version numbers, revision-protected edits. |
| Organization | `folders`, `document_stars` | Ownership/source scope, nested folders without cycles, personal favorites. |
| Collaboration | `document_shares`, `document_comments` | Live named grants, pinned versions, bounded escaped comments. |
| Source evidence | `source_records`, `source_history` | Latest accepted snapshot plus immutable receipt history; older deliveries cannot regress current state. |
| Integration | `integration_accounts`, `idempotency_requests`, `integration_events` | Hashed credentials, actor-scoped upload receipts and scoped event cursors. |
| Accountability | `audit_entries` | Application append-only activity; not claimed externally tamper-proof. |
| Framework | Cache/queue tables | Infrastructure availability, not proof that sync jobs run. |

File content lives in private storage, not MySQL BLOBs. Database metadata and bytes must be backed up and restored together. Nullable legacy/imported owners remain unassigned until explicit mapping. Current naming migration preserves bytes but can disambiguate existing display/download names; it is not reversible simply by dropping its key columns.

## 7. Main contracts and flows

### Upload and retrieval

Authenticate an active user/connector; authorize school/campus/source and ownership or grant; validate metadata and actual file content; enforce name/idempotency/revision rules; store a new private version; commit document metadata, audit and event; return the receipt. Do not run scanning or a business review.

Supported uploads are nonempty PDF, PNG, JPG/JPEG and UTF-8 TXT up to 10 MB. Folder intake is sequential with at most 50 files per batch. Successful items survive later failures and retries. Download/preview rechecks scope, grant/Trash where relevant and checksum, then returns private content. A checksum failure is a conflict, not permission to serve damaged bytes.

### Received history

`POST /api/v1/history` accepts a complete agreed snapshot with event ID, record type/ID, revision, operation, occurrence time and payload. Credentials supply source/school/campus. Body limit is 256 KiB. A transaction locks the scoped record, checks receipt/revision uniqueness, preserves the receipt and updates the current snapshot only when appropriate.

| Input condition | Expected result |
|---|---|
| New event and unused revision | 201; one receipt and audit; apply snapshot if newer |
| Same event and canonical content | 200; original receipt, no duplicate history |
| Same event with different content | 409; no silent overwrite |
| Competing event at occupied record revision | 409; investigate identity/revision ownership |
| Older unused revision | Preserve history with `applied=false`; current revision stays newer |
| Delete | Tombstone, preserving previous evidence |
| Bounded contention retries exhausted | Safe conflict; caller retries same event with backoff/jitter |

Complete snapshots need not arrive consecutively. Revision 12 before revision 11 leaves the current snapshot at 12 while retaining 11. This does not prove that revisions 1–10 were delivered.

### API surface

| Method | Route | Purpose |
|---|---|---|
| GET / POST | `/api/v1/documents` | Scoped listing / idempotent multipart intake |
| GET | `/api/v1/documents/{id}` | Authorized document metadata/version history |
| GET | `/api/v1/files/{id}` | Authorized private version download |
| POST / GET | `/api/v1/history` | Snapshot receipt intake / scoped history cursor |
| GET | `/api/v1/events` | Scoped outgoing document-change cursor |

Connector routes currently use a 120 requests/minute throttle. History reads use `after` and `limit` 1–100. Consumers commit their processing before advancing durable checkpoints. Document events and received source history are separate feeds and must not share an assumed universal cursor.

## 8. Current implementation status

| Capability | Status and evidence boundary |
|---|---|
| Local authentication/RBAC/accounts | Implemented; feature tests cover role, ownership, scope and inactive-account boundaries. SSO is pending. |
| Files/folders/grid/list/Trash/stars | Implemented; production usability and visual review remain incomplete. |
| Viewer/commenter/editor shares | Implemented with pinned versions, expiry and revocation. |
| Immediate uploads and name conflict protection | Implemented; database keys protect collisions. Unicode normalization improvements are not implemented. |
| Received snapshots/history/replay/order/tombstones | Implemented baseline; generic payload schema, no automatic source import. |
| Scoped tokens and event feed | Implemented; operational rotation/reconciliation tooling remains pending. |
| UI refinement | Recent Blade/CSS fixes implemented; Figma sidebar transfer blocked by tool quota, not completed. |
| Tests | Latest local application verification: 30 tests / 323 assertions; frontend folder-upload regression previously passed. This documentation update is not a new runtime verification. |
| Database target | MySQL. Local preview remains SQLite; existing CI is configured for PHP 8.2/MySQL 8.4. Check actual CI results before claiming verification. |
| Deployment readiness | Not established: real source contracts, MySQL contention, recovery, governance and operations still require evidence. |

## 9. Delivery phases and exit criteria

| Phase | Work | Exit evidence | Dependencies |
|---|---|---|---|
| P0: Architecture and documentation | Adopt final reference; remove contradictory current requirements; record boundaries and ownership | Linked diagram, AGENTS/master plan, accurate contracts and decision log | User-approved architecture and scanning decision |
| P1: Student experience | Finish sidebar, responsive hierarchy, navigation, modal/forms, keyboard help and sharing states | Desktop/mobile visual review plus functional upload/folder/share checks; no overflow or unreadable states | Accessible design reference; working app |
| P2: DMS correctness | Validate names, versions, grants, race/error paths, migration and storage failures | Targeted regressions and isolated MySQL results; competing writes cannot corrupt state | Test MySQL and representative files |
| P3: Integration contract | Agree per-source IDs, schemas, field grants, revisions, snapshots, errors and credentials | Four signed-off contract fixtures and conformance results | Four source architects and enterprise architect |
| P4: First source end-to-end | Start with Admission/Enrollment contract readiness; implement one agreed adapter/import/reconciliation path | Snapshot plus changes survives replay, outage and reconciliation without regression | Source snapshot/change cursor, reliable delivery mechanism |
| P5: Remaining sources | Add other ready sources, then restricted employee/payroll evidence | Same conformance/recovery checks per source; payroll access review | P4 lessons, source-side delivery and policy |
| P6: Operations and pilot | Production MySQL, HTTPS, backup/restore, monitoring, privacy/retention, support procedures | All release gates below pass; controlled pilot and accountable sign-off | Infrastructure and school policy owners |

These phases are dependency-based, not invented calendar commitments. DMS work can proceed before all groups finish; lossless import cannot proceed without an adequate source contract. Do not rewrite the application into new architectural layers to compensate for missing source information.

## 10. Prioritized product backlog and acceptance criteria

This active backlog has 24 stories, below the original maximum of 50. It supersedes contradictory review/scanning items in the historical 45-story proposal. “Baseline” means implemented and still subject to verification, not newly promised work. AC lists are minimum release checks.

| ID / priority | User story | Acceptance criteria | State |
|---|---|---|---|
| DMS-01 / P0 | As a maintainer, I need one current architecture reference. | Visible final diagram is checked in; tier/database boundaries match both new documents; scanning is explicitly absent. | This documentation increment |
| DMS-02 / P1 | As a student, I need a readable sidebar matching the approved design. | Available design is compared with rendered output; all six personal destinations work; no office controls; mobile navigation closes without losing context. | Open; design access blocked |
| DMS-03 / P1 | As a user, I need clear page hierarchy and contextual help. | One page h1; `?` beside title works on hover/focus; headings/body readable at normal zoom; no overlap at 360/768/1440px. | Refinement open |
| DMS-04 / P1 | As a user, I need usable dialogs. | Background scroll locks/restores; Escape/close work; labels/focus are clear; invalid required field becomes visible; repeated openings do not duplicate handlers. | Baseline; verify |
| DMS-05 / P1 | As a student, I need private immediate file upload. | Supported valid file becomes available without review/scan; unauthorized access is rejected; MIME/size/empty violations give usable errors. | Baseline |
| DMS-06 / P1 | As an owner, I need unique names without overwriting evidence. | Case-insensitive filename/title conflicts across folders/Trash return 409; competing requests cannot both create conflicting rows; different owners may reuse names. | Baseline; MySQL race check open |
| DMS-07 / P1 | As an owner, I need replacement versions. | Older bytes remain retrievable by authorized users; stale revision conflicts; new version has a new UUID/checksum; replacement does not collide with another document name. | Baseline |
| DMS-08 / P1 | As a user, I need folders and folder intake. | Nested paths remain scoped/cycle-free; `+ New` exposes all three actions; <=50 files upload sequentially; failures retain successes and retry does not duplicate them. | Baseline |
| DMS-09 / P1 | As a user, I need search and grid/list organization. | Search/sort/pagination preserve scope; long names do not overlap actions; Home/folders/Recent/Starred/Trash show appropriate empty states. | Baseline; visual checks open |
| DMS-10 / P1 | As an owner, I need recoverable Trash. | Trashed files disappear from active access; restore retains versions; stale actions conflict; Trash still reserves names. | Baseline |
| DMS-11 / P1 | As an owner, I need controlled sharing. | Named same-campus active recipient, permission and expiry validated; grant pins a version; revoke/expiry/Trash stop subsequent retrieval. | Baseline |
| DMS-12 / P1 | As a recipient, I need Shared with me and audience-specific actions. | Viewer cannot comment/edit; commenter cannot rename/upload; editor cannot move/delete/reshare; unauthorized direct endpoints fail; new versions retain original pinned share. | Baseline |
| DMS-13 / P1 | As a user, I need trustworthy details/activity. | Selected item populates scoped metadata/activity; switching selection cannot display stale response; close/list/grid remain usable; no business-review controls. | Baseline; visual checks open |
| DMS-14 / P1 | As an admin, I need scoped accounts and audit. | Personal users cannot administer; new scope inherited; invalid roles rejected; self-demotion/deactivation blocked; inactive sessions lose access; changes audited. | Baseline |
| DMS-15 / P2 | As an operator, I need safe database migrations. | Restore backup exists; naming migration is rehearsed on representative MySQL data; collisions disambiguate without byte loss; rollback limits and large-dataset cost documented. | Open |
| DMS-16 / P2 | As a developer, I need proof under competing writes. | MySQL tests race duplicate intake, same document revision, version numbering, grants and history; valid winner and safe loser; no partial evidence/orphaned references. | Open |
| DMS-17 / P2 | As an operator, I need safe upload failure recovery. | Simulated storage/write/commit failures leave no exposed or falsely acknowledged version; cleanup/reconciliation procedure identifies orphan bytes without deleting referenced content. | Open |
| DMS-18 / P3 | As a source architect, I need an agreed v1 contract. | Each source defines IDs/types/allowed fields/revisions/events/errors and sample fixtures; secrets excluded; unknown schema versions fail safely; conformance tests pass. | Open |
| DMS-19 / P3 | As a source, I need replay-safe history delivery. | Same event/content replays; altered replay/revision collision conflicts; 12-before-11 cannot regress snapshot; tombstone preserved; source boundaries and cursors tested. | Baseline; source conformance open |
| DMS-20 / P4 | As an integration operator, I need initial import plus ongoing changes. | Consistent snapshot/change checkpoint agreed; interrupted import resumes; source retries durable deliveries; newer changes beat older snapshot; expected counts/revisions reconcile. | Open |
| DMS-21 / P4 | As an operator, I need credential and outage handling. | Scoped token issuance/disable/rotation runbook; retry backoff/jitter; checkpoints persist; revoked tokens rejected; logs contain no secrets; persistent failures actionable. | Partial baseline; operations open |
| DMS-22 / P5 | As an authorized office, I need safe identity links and minimal source evidence. | Agreed stable mapping, no name-only auto-link; restricted payroll inaccessible to personal/foreign roles absent explicit permitted grant; correction/export flows tested. | Open |
| DMS-23 / P6 | As the school, I need governed retention and recovery. | Approved category retention/hold/disposal rules; held evidence cannot purge; authorized disposal auditable; joint database/file restore meets agreed recovery targets. | Open |
| DMS-24 / P6 | As the school, I need an operable production pilot. | HTTPS/public-only deployment, least-privilege DB, encrypted backups/secrets, monitoring, rollback, accessibility/usability review, no demo accounts and documented release sign-off. | Open |

## 11. Coordination and evidence ownership

The DMS architect owns this repository, schema, access rules, document/history API and DMS operational runbook. Each source architect owns business authority, source schemas, revisions, export and durable delivery. The enterprise architect resolves cross-system identifiers, contract compatibility, boundaries and rollout order. School policy owners approve privacy, retention, identity provisioning and operational access.

Track each integration agreement with: source owner; contract version; record types/IDs; permitted fields; event/revision strategy; snapshot/change consistency; delete semantics; authentication/rotation; retry/error rules; mapping; test fixtures; reconciliation; retention dependencies; rollout and rollback. A contract change needs both producer and consumer review. Do not silently rename configured source identifiers to match presentation labels.

## 12. Production release gates

All gates require recorded evidence and an accountable owner. Unchecked items block a production claim.

- [ ] Final diagrams and current contracts match actual behavior; no active scan/review requirement.
- [ ] Student/staff/admin desktop and mobile journeys reviewed visually and functionally; keyboard access works.
- [ ] Isolated MySQL suite and real multi-writer tests pass on the selected deployment version.
- [ ] Migrations rehearsed with recoverable data; private bytes and metadata remain consistent.
- [ ] Required sources pass conformance and initial/change/reconciliation tests; deliberately absent connections identified.
- [ ] Role/grant/inactive account and cross-school/campus access tests pass.
- [ ] School-approved identity, permitted fields, privacy, retention/hold/disposal policies recorded.
- [ ] HTTPS, public-only web root, least-privilege accounts, production secrets, backup encryption and debug-disabled config verified.
- [ ] Restore drill meets school-approved RPO/RTO; keys/configuration recovered and checksums validated.
- [ ] Logs/alerts, disk capacity, failed-delivery/conflict handling, credential rotation and support ownership established.
- [ ] Demo accounts/data removed from production; pilot, rollback and release acceptance recorded.

National/institutional requirements are policy inputs, not blanket validation rules. `docs/PH-REQUIREMENTS.md` is historical research; current applicability must be verified with official sources and school owners before being treated as an operating obligation.

## 13. Explicit exclusions and next action

No automatic full-database replication, direct external database writes, DMS business approval, scan pipeline, anonymous public links, folder sharing, browser content editor, assumed SSO, guaranteed source completeness, or guaranteed API-only plug-and-play setup. Introduce these only through a new explicit requirement and impact review.

Next implementation focus: finish and visually verify the student's navigation/sidebar and main file journeys, then validate MySQL collision/transaction behavior. In parallel with that independent work, obtain the four source contracts through the enterprise coordination process. Record each completed story with the commit, test result and remaining limits rather than changing “Open” to “Done” based on a screenshot or proposal.
