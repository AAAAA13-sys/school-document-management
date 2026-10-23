# Staff guide

Sign in with your invited email address and the DMS password you chose. Personal and institutional email addresses are accepted. Request an invitation from your administrator; public registration is unavailable. Use Forgot your password for a single-use recovery email. Inactive accounts lose access, and password recovery revokes existing sessions.

## Workspaces and documents

The workspace selector lists your active registered memberships. Super Admin manages repositories in its school and campus; Admin manages assigned workspaces; Staff manages owned files and folders and can edit documents with an Editor grant. Internal documents are source-visible, Confidential documents need ownership, the source Admin or an explicit document grant, and Restricted documents need ownership, Super Admin or an explicit grant. School/campus isolation always applies.

Upload files up to 10 MB each, or a folder batch of up to 50 files. Versions are immutable and private. New versions remain Pending until the background scanner reports Clean. Downloads and previews recheck SHA-256; unsafe, failed and unavailable bytes stay blocked. Scan status is a security result, not a business approval.

Use document details to rename, add versions, comment, move, star, request trash or request restoration. Editor grants permit rename and version upload, never move, trash, reclassification or resharing. Trash preserves every version. New folder sharing is unavailable; existing historical folder grants remain compatible.

## Requests and approvals

Sharing, classification, trash, restore and official publication create requests for Staff and Admin. Live state remains unchanged until approval. Staff requests go to an Admin assigned to the document workspace; Admin requests go to Super Admin. Use Requests and approvals to inspect proposed changes and approve or reject with a reason. Self-approval is blocked; stale requests require a new proposal. Super Admin document changes apply directly within scope.

Document grants use Viewer, Commenter or Editor. Viewer downloads; Commenter also comments (2,000 characters maximum); Editor can rename and add versions. Grants expire within 30 days and can pin Viewer/Commenter to a specific version. Restricted shares remain Super Admin actions. Named recipients must be active within the same school and campus.

Publish an official memo from its document page: choose a Clean version, type, reference, issuer and named audience. Approval pins that exact version for circulation. A later upload does not replace it until a new publication is approved.

## Accounts and administration

Super Admin invites Admins and manages registered workspaces, memberships and ERP connectors. Admin proposes Staff invitations; Super Admin reviews them before email is sent. Invitations expire, can be revoked, and resend invalidates the earlier link. Only Super Admin grants Admin or Super Admin authority. Account changes requested by Admin require Super Admin review under Account requests. Self-demotion, self-deactivation and administrator-set passwords are blocked.

Invitation status Sent means accepted by the configured outbound mail transport, not proof of recipient delivery. Log/array mail transports cannot send account links. The invitation remains available for retry if transport configuration fails.

Received history and Current records preserve ERP receipts and newest snapshots without changing the originating ERP. Staff cannot access received history. Audit and blocked-file screens remain scoped to visible records; there is no manual scan bypass.

OAS releases verified admitted-applicant packets through the API; ES retrieves only the specifically released Clean document/version IDs. Applicants and students never receive DMS accounts. Medical files remain excluded from ordinary OAS/ES connector intake and reads pending agreed access rules.
