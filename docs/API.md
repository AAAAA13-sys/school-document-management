# ERP API v1

One contract serves EMS, PMS, ES and OAS. Each ERP team supplies its own adapter/outbox; DMS never connects to their databases. Staff UI and files work without any ERP being connected. Base URL is your deployment's `/api/v1`. [OpenAPI JSON](openapi.json) describes the endpoints.

## Authentication and limits

Create a connector in DMS Admin → ERP connectors and copy the token once. Send `Authorization: Bearer <token>` on every request and `Accept: application/json`. Tokens are hashed and fixed to one source, school and campus. Do not submit these scope fields in history/upload requests. Use HTTPS outside loopback development. Keep tokens server-side, outside browser bundles and source control. Revoke and replace a token from administration when needed.

120 requests/minute per connector; 429 responses carry retry timing. Files max 10 MB, actual MIME validation; history payload max 256 KiB, total JSON body 264 KiB. Cursor page limit 1–100, default 50. Staff folder import (session/CSRF endpoint `/documents/batch`) caps batches at 50; ERP adapters send individual document requests with independent keys.

## History intake

`POST /history`, Content-Type application/json:

```json
{
  "event_id": "ems:employee:EMP-001:1",
  "record_type": "employee",
  "record_id": "EMP-001",
  "revision": 1,
  "operation": "upsert",
  "payload": { "department": "Faculty", "external_id": "EMP-001" }
}
```

event_id, record_type and record_id are strings up to 100 characters. Revision is an integer 1–2147483647, monotonically increasing for one record. operation is upsert or delete. payload is a JSON object or list. It is source evidence, never instructions to create DMS accounts. JSON object key order is canonicalized recursively; list order, scalar types, empty objects/lists, whitespace inside strings and empty strings are preserved. Identifiers are case-sensitive. The API does not trim source data.

New event: 201 `{"history_id":1,"applied":true,"duplicate":false}`. Identical event replay: 200 with the original history_id/applied and duplicate=true. Same event ID with changed content: 409. Different event ID claiming an already-received record revision: 409. Older, previously unseen revision: 201/applied=false, receipt preserved, latest snapshot unchanged. A newer delete stores a tombstone; send an empty payload if appropriate. All constraints are scoped to the connector's source/school/campus. There are bounded lock/deadlock retries, followed by 409.

Retry network failures, 409 contention, 429 and transient 5xx with the exact same event/key/content and exponential backoff plus jitter. Persistent content/revision conflicts need operator resolution; do not silently assign a new event ID. A retry after a successful newer event still returns the original earlier receipt. Do not log tokens or sensitive payloads.

## Files

`POST /documents` is multipart/form-data with an `Idempotency-Key` header (required, 1–100 characters), file, title (1–200) and classification (Internal, Confidential or Restricted). Optional: subject (200), reference/category (100). Do not send source/school/campus. Folder IDs refer to staff-owned folders and are not valid destinations for ERP-owned uploads; omit folder_id.

201 response: `{"document_id":"uuid","version_id":"uuid","revision":1,"scan_status":"Pending"}`. Bytes are privately stored, then the database-backed worker scans after commit. Identical retries return 200 and the original receipt (including its original Pending status); changed key content is 409. Read current scan status from `GET /documents/{document}`. A reused key applies across connector identities within the same source scope.

`POST /documents/{document}/versions`: same multipart/key rules, plus the document's current revision. Metadata/classification are inherited. A matching revision produces the next immutable version; stale revision returns 409. Repeat with the same key/content to recover a lost response without creating another version.

`GET /files/{version}` downloads only a Clean, untrashed version from this connector's scope. Pending/Failed/Quarantined returns 423. A missing stored file or checksum mismatch returns 409. No filesystem path is exposed. Authenticated web previews support scanned PDFs, common raster images, plain text, CSV and JSON; API clients continue to use downloads.

```bash
curl "$DMS_URL/api/v1/history" -H "Authorization: Bearer $DMS_TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @event.json
curl "$DMS_URL/api/v1/documents" -H "Authorization: Bearer $DMS_TOKEN" -H 'Accept: application/json' -H 'Idempotency-Key: evidence-001' -F 'title=Employee evidence' -F 'classification=Restricted' -F 'file=@evidence.pdf'
```

## Read-back

GET `/history`, `/events`, `/documents`, `/files`: `?after=0&limit=50`. Response: `{"data":[],"next_cursor":0,"has_more":false}`. Save next_cursor only after processing the page; repeat while has_more=true. Cursors are opaque numbers, independent for each resource/source. History also accepts record_type and record_id filters.

History and events use increasing receipt/event IDs. File cursor represents version creation, not later scan changes. Documents cursor walks integration events and hydrates the current document state, potentially returning a document again after changes. Consumers must upsert by UUID, not assume each page contains only new documents. A `document.scan.completed` event tells a consumer to reread document/version status. Soft-trash state is included in document metadata. GET `/documents/{document}` returns metadata and versions, excluding private paths. GET `/snapshots/{type}/{record}` returns the current snapshot, including deletion state. URL-encode record identifiers.

Errors use Laravel JSON `{message, errors?}`: 401 invalid/revoked token; 404 unknown/out-of-scope resource; 409 replay/revision/integrity conflict; 422 invalid input; 423 blocked file; 429 throttled; 5xx unavailable infrastructure. With APP_DEBUG=false, responses do not expose SQL or stack traces. Browser session endpoints and staff credentials do not authenticate the API.

## ERP handoff checklist

For each source, provision a scoped connector; submit/replay an event; reject changed replay; send newer then older revisions; verify a deletion tombstone; upload a file and observe Pending → Clean (or blocked failure); exercise cursor reads; verify another source's UUID is inaccessible; test revocation and backoff. The automated suite covers all four source identities plus simultaneous MySQL replays. Live ERP endpoints/credentials were not supplied, so source-team deployment connections are not configured here.

Application and MySQL session timestamps use UTC; clients should display them in the appropriate local timezone.

Upload formats (2026-10-17): single-file, version and folder uploads accept any file extension, including extensionless files. The 10 MB per-file limit, actual-file validation, detected MIME metadata, private UUID storage and automatic ClamAV gate still apply. Downloads use attachments; unsupported preview formats remain download-only. Encrypted/oversized-to-scan archives may remain blocked by ClamAV.
