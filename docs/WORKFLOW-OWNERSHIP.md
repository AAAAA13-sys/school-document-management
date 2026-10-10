# Current workflow ownership

The user's revised requirement supersedes the original review/verification stories: DMS stores, organizes, shares and preserves documents and source history. Business verification and approval belong to users' processes or originating systems. DMS provides no approval/rejection endpoint or review queue.

Valid uploads are Available without any reviewer decision or scanning prerequisite. Scanning was explicitly removed. Upload type/content/size validation, checksum integrity, access scopes, audit and versions remain enforced. Retention/hold/disposal procedures still require school policy and implementation.

Existing review metadata and audit/events remain historical evidence. New versions use Available immediately after validation. The document status filter accepts Available only. Compatibility fields can retain `scan: Not required` and zero scan counts; they are not evidence of a running scanner. The naming migration updates legacy Pending/Failed versions to Not required/Available and can disambiguate existing names without changing file bytes. Consumers relying on old review/scan filters must update.

The original BASELINE.md and dated research remain provenance, not the current workflow specification. Their review-story acceptance conditions are superseded by this revision. Live user-defined workflow engines are not implemented inside DMS.
