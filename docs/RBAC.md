# Role-based access

> Superseded scope notice (16 October 2026): the final SDD requires staff-only DMS access and ClamAV scan gating. Earlier student-access and scanning-removal descriptions below are historical. Use [SDD-ALIGNMENT.md](SDD-ALIGNMENT.md) and the root master plan for current behavior.


Role checks run on the server. School and campus boundaries apply to every role.

| Role | Document access | Administration |
|---|---|---|
| Student | Own uploads and folders in Admission/Enrollment; organize, version, preview and download own files after upload validation and integrity checks | None |
| Teacher / Employee | Own uploads and folders in Employee Management; organize, version, preview and download own files after upload validation and integrity checks | None |
| Admin | All four sources within assigned school/campus | Create accounts, change roles, activate/deactivate other accounts; audit and history |
| Registrar | Admission and Enrollment records | Existing source-scoped history |
| HR | Employee Management records | Existing source-scoped history |
| Payroll | Payroll Management records | Existing source-scoped history |

Teachers and employees are separate account roles with the same permissions. Neither grants HR or payroll officer access. Students/teachers/employees cannot read institutional history, integration feeds, audit or account management. New personal uploads and folders receive server-assigned `owner_user_id`; request metadata cannot assign ownership. Existing records retain null ownership and remain visible only to authorized office/admin roles. Connector uploads do not automatically match a student or employee identity.

Admins use **Accounts & roles** (`/accounts`). New accounts inherit the admin's school/campus, use confirmed passwords with at least 12 characters including letters and numbers, and start active. Role/status changes are audited. Admins cannot change their own role/status through this page. Deactivation blocks existing authenticated sessions' workspace requests as well as new sign-ins. No public self-registration or client-controlled role assignment exists.

Named shares explicitly grant access to one pinned document version within the same school/campus, even when the recipient does not own it or have its source-wide access. Viewer permits reading/download; commenter also permits adding comments; editor also permits renaming and uploading a new immutable version. Grants expire within 30 days and can be revoked. Recipients cannot delete, move or reshare the document through the grant. New versions retain ownership and upload/integrity controls. Scanning is removed. Shared with me lists live grants; each operation rechecks the grant, sender access and document scope. Shared writes lock the grant and document inside a transaction. Shared editors do not edit file contents in the browser. Imported record-to-person identity mapping remains future work.

Folder shares (`folder_shares`) follow the same named-recipient rules: same school/campus, active account with a workspace role, Viewer or Commenter, expiry within 30 days, revocable by the sharer, and recorded as `Folder shared` / `Folder share revoked` audit entries. A folder grant reaches the folder and every folder nested inside it, and grants read access to the documents stored there; the ClamAV clean-result rule still gates preview and download. Folder recipients cannot rename, recolor, move, trash or reshare the folder or its documents, cannot create content inside it and cannot read the share settings — every one of those endpoints returns 404 or 403 for them. Sender activity and account status are rechecked on each folder-grant operation, and folder sharing never widens a recipient's access to any other source, document or folder. Owner/office roles keep their existing source-scoped management rights over the same folders. Anonymous or public link sharing is not implemented.

Local demo accounts: `student@demo.school`, `teacher@demo.school`, `employee@demo.school`, plus existing admin/office accounts. Passwords come from `DMS_DEMO_PASSWORD`; never deploy demo accounts to production.

Verification: ownership isolation, preview/download protection, forbidden administration/history/feed access, source restrictions, scoped account changes, password hashing, invalid roles, self-demotion protection and inactive sessions are covered by `RoleAccessTest`. SQLite regression tests do not establish multi-writer MySQL behavior.
