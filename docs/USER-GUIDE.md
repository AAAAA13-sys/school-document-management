# Staff guide

Sign in with the staff email and password supplied by your administrator. Five failed sign-in submissions per minute per email/IP are throttled, with an additional IP limit. Inactive accounts cannot sign in and lose existing sessions. Sign out from the sidebar. My account changes your password and signs out your other sessions; contact an administrator to change your name, email, role or source.

## Workspaces and documents

Employees use their own source workspace and only see their own files or active grants. HR officers read EMS records, payroll officers PMS, registrars ES/OAS, and administrators all four sources within their school/campus. Removing the sidebar repository notice does not remove these scope checks. No student DMS accounts are supported.

Upload document accepts a supported PDF, Office file, text/CSV or PNG/JPEG up to 10 MB, validated by actual content. Choose a classification and optional folder/reference. The document is saved immediately as Pending, then ClamAV runs in the background. Keep the details page open to see the real scan status update. Failed versions retry automatically; Quarantined versions remain blocked. Contact an administrator if a version stays blocked. There is no manual Scan button.

Upload folder imports up to 50 files and their nested folder structure. Select a destination folder first if needed. Invalid files/path names reject the batch. A partial server conflict identifies the affected paths; retry the same form to resume with its original submission key. Do not reload a failed form before retrying if you need that key preserved.

Open a document to see immutable earlier versions, metadata, comments and activity. Upload new version preserves every prior file; competing edits require a reload. Downloads are allowed only after Clean and a fresh checksum check. Encrypted or unscannable files remain blocked. A scan result does not approve employment, payroll, enrollment or admission business decisions.

Create folders, open a folder and use Edit folder to rename/move it; cycles and other owners' destinations are rejected. Rename or move document is available to its owner. Star files for quick access. Trash blocks downloads but preserves all versions; restore from Trash. There is no permanent-delete action.

## Sharing and history

Owners share with named active staff in the same school/campus. Viewer downloads; Commenter also comments (up to 2,000 characters); Editor also renames and uploads new versions. Editors cannot move, trash or reshare. You can pin a Viewer/Commenter to one version, set an expiry, or revoke access. Pinned grants never expose another version through the download endpoint. Office/admin source access remains independent of an optional share.

Received history shows immutable ERP receipts. Current records shows the newest received revision, including deletion tombstones. Older events are preserved without replacing a newer snapshot. These screens do not edit the originating ERP. Employees cannot inspect source history.

## Administration

Staff accounts creates, edits, activates/deactivates and changes roles. New accounts inherit the administrator's school/campus. Set a temporary strong password and ask the person to change it under My account. Editing an existing account leaves the password unchanged when blank. Password resets and deactivation revoke older sessions. Self-demotion and self-deactivation are rejected. Public registration is unavailable.

ERP connectors creates one independently revocable token per source identity. Copy the raw token once and give it securely to that ERP team; it is never displayed again. Token hashes, not raw tokens, are stored. Connectors cannot administer staff or cross source/school/campus boundaries. Multiple connector identities for the same source share event/submission idempotency.

File security lists blocked versions. It does not offer a release/bypass button. Audit trail records uploads, scans, downloads, history intake, sharing and account/connector changes. The notification popover explains scanning; it is not a delivery/alert service. External alerts and retention policies remain open SDD decisions.
