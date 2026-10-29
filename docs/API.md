# ERP API v1

One contract serves EMS, PMS, ES and OAS. Each ERP team supplies its own adapter/outbox; DMS never connects to their databases. Staff UI and files work without any ERP being connected. Base URL is your deployment's `/api/v1`. [OpenAPI JSON](openapi.json) describes the endpoints. The API uses HTTP requests over HTTPS in deployment; there is no WebSocket.

## Start with Postman

Postman is a client for sending API requests manually. It is useful for an ERP team's first connection test; the production ERP adapter must make the same requests from its server and maintain an outbox for retries. Import the [DMS ERP v1 collection](postman/DMS-ERP-v1.postman_collection.json) using **Postman → Import → File**. Importing `openapi.json` is also supported, but the collection follows this test sequence.

1. The DMS operator deploys a reachable HTTPS URL and configures the database, queue worker, scheduler and scanner as described in [SETUP.md](SETUP.md). A local `127.0.0.1` URL is only reachable from the same machine. A Super Admin registers an active ERP workspace and creates a separate connector under **Admin → ERP connectors** for each ERP. The token appears once.
2. In Postman, create a private environment with `baseUrl` and `connectorToken`. Set `baseUrl` to the DMS origin without `/api/v1` or a trailing slash. Store the token as an environment secret/current value. Select that environment. Never export real tokens, personal records or sensitive payloads in a collection or screenshot.
3. Send **01 - Read history**. HTTP 200 with an empty `data` array proves authentication, not that an ERP event has arrived. HTTP 401 means the token is missing, invalid or revoked; HTTP 403 can mean the connector's ERP workspace is inactive.
4. Send **02 - Submit history event** with synthetic data. Expect HTTP 201. Send the identical request again and expect HTTP 200, the original `history_id` and `duplicate:true`. Changing content under the same event ID must return 409.
5. Send **04 - Upload document** with a small benign file selected in **Body → form-data**. Use a unique `Idempotency-Key`, and keep the same key and content for retries. Copy `document_id` and `version_id` into the environment. Use **05 - Read document** until the version is `Clean` before **06 - Download file**. The scan worker must be running. A 409 `duplicate_file` result needs an explicit duplicate decision; `confirm_duplicate=true` permits an intentional second copy with the same key.
6. Use **07 - List files** and **08 - List events** with independent cursors. Process each page before saving `next_cursor`, and continue while `has_more` is true. OAS may use **10 - OAS release packet** for exact Clean versions; ES must switch to its own connector token for **11 - ES read packet** and **12 - ES download packet file**.

The collection's IDs and payloads are examples. Agree actual record types, fields and document categories with each ERP team before using real data. The DMS repository contains no ERP-side adapter or credentials. A Postman success alone does not establish a live ERP integration.

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

Duplicate files (advisory, SHA-256): before any new document is stored, its checksum is compared with versions the connector may already read in its own source/school/campus. A match returns 409 `{"code":"duplicate_file","message":"...","duplicates":[{"document_id":"uuid","title":"...","version_number":1,"uploaded_at":"...","can_add_version":true}]}`. Only documents this connector is already authorized to read are listed, at most five, and no checksum, filename or out-of-scope metadata is disclosed. Nothing is stored and no document is created. Repeat the same request with `confirm_duplicate=true` to keep an intentional separate copy; the confirmation is not part of the idempotency hash, so the same `Idempotency-Key` still replays correctly. Re-versioning a document is never reported as a duplicate of itself. Automated copies of an already-known file (for example a reconciliation re-run) must send `confirm_duplicate=true` explicitly rather than silently retrying.

`POST /documents/{document}/versions`: same multipart/key rules, plus the document's current revision. Metadata/classification are inherited. A matching revision produces the next immutable version; stale revision returns 409. Repeat with the same key/content to recover a lost response without creating another version. The same advisory duplicate check applies against other documents; the document being versioned is always excluded, so an identical earlier version of the same document does not block a new version.

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

## Verified OAS-to-ES applicant packet release

OAS sends `POST /applicant-packets` JSON with `event_id` (stable retry identity), `applicant_id`, increasing integer `revision`, boolean `released`, `admitted`, `verified` and `version_ids` (exact UUIDs). Release requires admitted/verified assertions from OAS and at least one Clean OAS version in the connector school/campus. DMS preserves source evidence rather than performing admissions verification. Medical categories and Restricted documents are excluded.

New receipts return 201 with packet_id, revision and applied. Identical replay returns the original result with 200; changed content under an event ID or another event at the same applicant revision returns 409. Older revisions preserve a receipt with applied=false. Send a newer released=false event to revoke the packet; all prior receipts remain.

ES uses `GET /applicant-packets/{packet}` and `GET /applicant-packets/{packet}/files/{version}`. Each request rechecks active connector identity, school/campus, live release, allowed exact version, Clean scan state, trash state and medical exclusion. File download also rechecks SHA-256. Packet grants do not widen ordinary `/documents` or `/files` source access. Ordinary API memo read-back and downloads expose the approved published version, keeping later drafts out of circulation.

Workspace registration is database-driven; a connector must belong to an active ERP workspace. Internal workspaces cannot create valid ERP connectors. Human accounts and invitation candidates are never provisioned by history payloads.
