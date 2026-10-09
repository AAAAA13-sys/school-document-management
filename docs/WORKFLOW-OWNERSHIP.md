# Current workflow ownership

The user's revised requirement supersedes the original review/verification stories: DMS stores, organizes, shares and preserves documents and source history. Business verification and approval belong to users' processes or originating systems. DMS provides no approval/rejection endpoint or review queue.

Clean files are Available without any reviewer decision. Pending, failed or quarantined scans remain blocked. Malware checks, checksum integrity, access scopes, audit, versions and retention safeguards remain separate from business decisions.

Existing review metadata and audit/events are preserved as historical evidence. No previous decision is rewritten by this change. New versions use Available after a clean scan; operational status filters accept Available or Awaiting scan. Statistics expose total, available and scan counts, not approval counts. Consumers using old filters or approval statistics must update.

The original BASELINE.md and dated research remain provenance, not the current workflow specification. Their review-story acceptance conditions are superseded by this revision. Live user-defined workflow engines are not implemented inside DMS.
