# Role-based access

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

Local demo accounts: `student@demo.school`, `teacher@demo.school`, `employee@demo.school`, plus existing admin/office accounts. Passwords come from `DMS_DEMO_PASSWORD`; never deploy demo accounts to production.

Verification: ownership isolation, preview/download protection, forbidden administration/history/feed access, source restrictions, scoped account changes, password hashing, invalid roles, self-demotion protection and inactive sessions are covered by `RoleAccessTest`. SQLite regression tests do not establish multi-writer MySQL behavior.
