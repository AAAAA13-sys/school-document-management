# Drive-style workspace

> Superseded scope notice (16 October 2026): the final SDD requires staff-only DMS access and ClamAV scan gating. Earlier student-access and scanning-removal descriptions below are historical. Use [SDD-ALIGNMENT.md](SDD-ALIGNMENT.md) and the root master plan for current behavior.


The file workspace adds nested source-scoped folders and breadcrumbs, grid/list layouts, name search, name/modified sorting, paginated files, Recent, personal stars, recoverable Trash, rename, move, file details/version history, safe previews and named sharing.

Folders belong to a source system and school/campus and carry a local owner. Students, teachers and employees see their own folders; office/admin roles see their permitted institutional workspace. My files expands into a nested folder list in the sidebar when folders exist. Folder-list refresh respects the expansion state, including after creating the first folder in an empty workspace. Starting a new file-list request clears the selected-item details and pending inspector request so the previous selection is not shown during navigation or after a failed list request. Moving a file does not change its source, subject, classification or ownership. Folder creation and rename are supported; folder deletion/movement and bulk operations are not included. Files can be moved between folders of the same source or returned to the root.

## Opening and coloring folders

Clicking anywhere on a folder card opens that folder directly, in grid, list and Home layouts. Rename and Folder details remain available in the card's actions menu, so opening a folder no longer requires an intermediate choice. The name button keeps an explicit "Open folder …" accessible name, and menu clicks never navigate.

Each folder stores an optional color from one fixed institutional-neutral palette (Default, Green, Yellow, Teal, Blue, Purple, Amber, Rose, Slate). The color is chosen when creating a folder and changed from the folder card's menu or the folder details inspector; opening a folder does not show a color control. Colors apply to every role that can see the folder, appear on the folder card chip, breadcrumb and sidebar tree, and change only presentation metadata: source, school/campus, ownership, contents and authorization are untouched. The server accepts only palette tokens, compares the folder revision, records `Folder color changed` in folder activity and the audit trail, and returns the applied color and new revision so a second change never sends a stale revision. Colors are tints and dots only, so folder names keep the standard text contrast.

## Sharing a folder

The folder card menu has **Share**, which opens a share sheet for people who can already manage the folder. It lists the owner and every current grant with role and expiry, invites an active workspace user in the same school/campus as Viewer or Commenter with an expiry inside 30 days, removes access, and copies a `?folder=<id>` workspace link. Anyone-with-the-link access is shown as unavailable because anonymous links are out of scope.

A recipient sees the folder at the top of their own file list with a `Shared · Viewer/Commenter` badge, opens it like any folder, and gets read access to the documents inside it plus preview and download of clean files and comment threads. Their card menu offers only Folder details, and their file menu offers Open, Preview and Download. Renaming, recoloring, moving, trashing, resharing and reading the share settings all return 404 for them, expiry and revocation remove access immediately, and nested folders are included with the grant. See [RBAC.md](RBAC.md) for the full rules.

## Upload and preview

The sidebar **+ New** menu contains New folder, File upload and Folder upload. Folder upload accepts up to 50 supported files per batch, preserves nested folders, and asks for common record details once. Uploads run sequentially through the existing authorized APIs; completed items remain saved if a later item fails. Retry reuses stored receipts and existing folders. Each file receives upload validation and is available without scanning. Directory selection requires browser support. Client retry behavior is checked with `node tests/Frontend/folder-upload.test.cjs`; native picker behavior needs browser verification.

Drop one file onto the file area to open the metadata/upload dialog, or use File upload. Required metadata, authorization and actual-content validation remain mandatory; there is no scan gate. Accepted files remain PDF/PNG/JPG/TXT up to 10 MB. A successful upload in a folder is moved there through a second authorized, revision-checked operation; if that operation fails the file remains safely in the root and an error is shown.

Valid files are immediately previewable. Each preview rechecks authorization, file existence and checksum, returns private/no-store and nosniff headers, and runs under a sandboxed frame/content policy. It never interprets uploaded HTML as application content. Browser PDF support may vary. Document details retain earlier versions, historical decision metadata and downloads.

## Trash

Trash is recoverable, with no automatic purge or permanent-delete button. It preserves file bytes, versions and audit history. Trashed documents disappear from active repository/search/statistics and cannot be downloaded, previewed or replaced. Restore uses the same source permissions and revision safeguards. A Trash action is not authorized archival disposal under institutional records policy.

## Sharing

Shares pin a valid file version to a named active account within the same school/campus for at most 30 days. They explicitly grant access to that document, without granting access to its source system or other documents. Shared with me lists active grants. Viewers read/download the shared version; commenters also add comments; editors also rename and upload immutable versions with revision checks. Recipients cannot move, trash or reshare through a grant. New versions receive upload validation without scanning and do not replace the version pinned by an existing link. Owners can read received comments in document details and revoke grants in the Share dialog. Expiry, revocation, account deactivation, loss of sender access and Trash block access.

The page title has a circled question mark with page-specific help on hover or keyboard focus. Anonymous/public links, external recipients and browser-based document content editing are not implemented.

## Verification

The isolated suite covers organization, personal stars, stale revisions, trash/restore, source boundaries, immediately available/checksummed previews and recipient/expiry/revocation checks. Earlier manual checks covered the initial grid/preview UI. Recent sharing/folder/header changes have automated backend tests and JavaScript/Blade checks; current visual verification remains incomplete. Real multi-user production load and live external connections remain unverified.
