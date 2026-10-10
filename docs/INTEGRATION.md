# Integration contract v1

This slice exposes a source-scoped pull API. It does not claim live ERP connectivity, webhook delivery, or complete reconciliation.

## Credentials

Create a separate token for each source using the command below in a secure terminal. The token is displayed once; only its SHA-256 hash is stored. The configured source, school and campus constrain every request. Revoke by setting `active=false` on its `integration_accounts` row through an authorized administration process. No unauthenticated token-management endpoint is provided.

```sh
php artisan dms:connector "Online Admission" --school=DEMO-SCHOOL --campus="Main campus"
```

Use `Authorization: Bearer <token>` and `Accept: application/json`. Use HTTPS outside localhost.

| Method | Endpoint | Behavior |
|---|---|---|
| GET | `/api/v1/documents` | Scoped list; `page`, `per_page` (max 100), `search`, `source`, `status` |
| GET | `/api/v1/documents/{id}` | Scoped metadata and immutable version history |
| POST | `/api/v1/documents` | Multipart upload; stable `Idempotency-Key` required |
| GET | `/api/v1/files/{version_id}` | Scoped download; requires authorization and matching checksum |
| GET | `/api/v1/events?after=0` | Ordered scoped events (max 100) and `next_cursor` |

## Upload

Required multipart fields: `title`, `subject`, `reference`, `source`, `category`, `classification`, `file`. Optional: `expires_at` in YYYY-MM-DD format. For replacement: also `document_id` and current integer `revision`, preserving subject/reference/source/category/classification.

Server supplies school/campus from the authenticated account; submitted values cannot override scope. Supported files are PDF, PNG, JPG and TXT up to 10 MB. Client extension and server-detected media type must match. Valid uploads are immediately available; there is no scanner prerequisite. Filenames and display names are case-insensitively unique within the owner workspace, including Trash. Payroll payslips require Payroll Management and Restricted classification.

201 result: `document_id`, `version_id`, `version`, `scan`, `status`. Repeating the same request/key returns the same receipt. Reusing a key with changed metadata or content returns 409. Keys are actor-scoped and retained indefinitely in this baseline; a production expiry policy remains to be defined. Clients should reuse keys after a network failure and use a fresh key for a changed request.

## Event contract

Envelope: `schema_version: 1`, `items`, `next_cursor`. Each item has ordered `id`, unique `event_id`, source/school/campus, `type`, document/version IDs, revision and timestamp. No subject names or document content are included.

Consumers persist their last processed cursor, deduplicate on event_id and compare document revisions before applying changes. Fetch until an empty page; resume from the last successfully committed cursor. Events and document changes share a database transaction. This is polling, not webhook delivery; push retries/replay and outage reconciliation remain in the baseline backlog.

Current emitted upload event types: `document.received` and `document.version_added`. Previously stored approval/rejection/scan events may remain historical records; these workflows are removed.

## Errors

401: missing/invalid connector token. 403: forbidden source/action or blocked download. 404: absent or out-of-scope document. 409: stale version, reused key with different payload, or checksum failure. 422: validation failure. Laravel returns structured `message` and, for validation, field `errors`.

Connector tokens cannot approve/reject documents or read the staff audit endpoint. DMS has no business-review endpoint; business decisions belong to users or source applications.
