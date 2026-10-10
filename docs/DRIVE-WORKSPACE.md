# Drive-style workspace

The file workspace adds nested source-scoped folders and breadcrumbs, grid/list layouts, name search, name/modified sorting, paginated files, Recent, personal stars, recoverable Trash, rename, move, file details/version history, safe previews and named sharing.

Folders belong to a source system and school/campus and carry a local owner. Students, teachers and employees see their own folders; office/admin roles see their permitted institutional workspace. My files expands into a nested folder list in the sidebar when folders exist. Moving a file does not change its source, subject, classification or ownership. Folder creation and rename are supported; folder deletion/movement and bulk operations are not included. Files can be moved between folders of the same source or returned to the root.

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
