# Drive-style workspace

The file workspace adds nested source-scoped folders and breadcrumbs, grid/list layouts, name search, name/modified sorting, paginated files, Recent, personal stars, recoverable Trash, rename, move, file details/version history, safe previews and named sharing.

Folders belong to a source system and school/campus, not an individual Google account. “My files” means files in the staff member's permitted workspace. Moving a file does not change its source, subject, classification or ownership. Folder creation and rename are supported; folder deletion/movement and bulk operations are not included. Files can be moved between folders of the same source or returned to the root.

## Upload and preview

Drop one file onto the file area to open the existing metadata/upload dialog, or use Upload document. Required school-record metadata and scan gating remain mandatory. Accepted files remain PDF/PNG/JPG/TXT up to 10 MB. A successful upload in a folder is moved there through a second authorized, revision-checked operation; if that operation fails the file remains safely in the root and an error is shown.

Only clean files can be previewed. Each preview rechecks authorization, file existence and checksum, returns private/no-store and nosniff headers, and runs under a sandboxed frame/content policy. It never interprets uploaded HTML as application content. Browser PDF support may vary. Document details retain earlier versions, verification decisions and downloads.

## Trash

Trash is recoverable, with no automatic purge or permanent-delete button. It preserves file bytes, versions and audit history. Trashed documents disappear from active repository/search/statistics and cannot be downloaded, previewed, reviewed or replaced. Restore uses the same source permissions and revision safeguards. A Trash action is not authorized archival disposal under institutional records policy.

## Sharing

Shares pin a clean file version to a named active user for at most 30 days. The recipient must already have the same school/campus/source permission; a link never expands those permissions. The recipient signs in before downloading. Unknown, expired, revoked or wrong-recipient links fail. Grant creation, revocation and access are audited. Grant owners see/revoke their grants from the Share dialog.

Anonymous public links, external recipients, permission elevation, collaborative editing, Google integration and Office previews are not implemented. The other groups still connect through the existing API contract. This increment does not claim the entire 45-story baseline or production hardening is complete.

## Verification

The isolated suite covers organization, personal stars, stale revisions, trash/restore, source boundaries, scan-gated/checksummed previews and recipient/expiry/revocation checks. Manual browser checks cover grid browsing, synthetic folder creation and the preview dialog. Real multi-user production load and live external connections remain unverified.
