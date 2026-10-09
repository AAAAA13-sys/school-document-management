# Central history synchronization — implemented contract

The DMS owns its database. Other groups submit source-owned snapshots through an authenticated integration endpoint; they do not write directly to its database. This implementation adds receipt history and a latest snapshot, not automatic access to unknown external APIs.

## Submit

`POST /api/v1/history`, using an existing source-scoped Bearer connector token:

```json
{
  "event_id": "e411edca-2267-4c89-bec2-25aa3f5bba45",
  "record_type": "student",
  "record_id": "STU-104",
  "revision": 12,
  "operation": "upsert",
  "occurred_at": "2026-10-07T20:00:00+08:00",
  "payload": {"enrollment_status": "enrolled", "academic_year": "2026-2027"}
}
```

- Send a complete agreed snapshot, not a patch. Revisions must increase per source/type/record across all that source's writers; do not reset on connector replacement. Maximum revision is 2147483647.
- Source, school and campus come from credentials. Payload fields cannot change that boundary. Identical record IDs from different sources remain separate.
- Preserve the event ID and content when retrying. A new receipt returns 201; an identical replay returns 200. Both include `history_id`, `applied` and `duplicate`. Object key ordering does not change replay identity; date-string representation and array ordering do.
- Event-ID reuse with altered content or another event for an already-received record revision returns 409. A contention conflict also returns 409: retry the same event with bounded exponential backoff and jitter; persistent conflicts need operator review.
- Late revisions are recorded with `applied: false`, never replacing a newer snapshot. No gap rejection: complete snapshots can arrive out of order. There is no guarantee that missing revisions were received.
- Deletion uses `operation: delete` and `payload: []`. It creates a tombstone; previous history remains subject to the institution's retention/hold policy. It is not a destruction request. A newer upsert may restore the current snapshot if the source intentionally does so.
- Request body limit is 256 KiB, metadata fields are bounded, and existing connector throttling applies. Send document files through the separate private upload endpoint, not base64 inside event payloads.

## Read

`GET /api/v1/history?after=0&limit=50` returns permitted source history and `next_cursor`. Limit is 1–100. Staff may use `GET /workspace/history` with their session; existing role/school/campus boundaries apply. Reads and new receipts are audited without copying payloads into the audit log.

History payloads are deliberately untrusted source evidence. They cannot provision accounts, change DMS permissions or decide document approval. No HTML rendering or automatic person linkage is performed.

The **Central history** workspace screen supports source and exact-record filters, receipt-order paging, refresh and a read-only comparison of the received revision with the current snapshot. `GET /workspace/history/{id}` supplies that comparison with the same school/campus/source permissions. JSON contents are rendered as text, never executable HTML. Filter options on the list API are `source` and `record_id`; they narrow existing permissions.

## Race protections

A transaction creates/locks the record slot, performs a compare-and-set revision update and commits history plus audit atomically. Unique database constraints protect event receipts and per-record revisions. Different sources never compete for one shared person row. Replayed events cannot create a second receipt. Database deadlocks receive bounded transaction retries; exhausted lock/uniqueness conflicts return a safe retry response.

The automated SQLite suite covers replay, changed content, out-of-order revisions, revision collisions, tombstones, source isolation and cursor reads. It does not constitute a multi-writer load test or certification on PostgreSQL/MySQL. Validate contention and throughput on the selected production database before deployment.

## How the other groups connect

1. Agree the permitted record types/fields and identity mappings. Never replicate password hashes, access tokens or unnecessary personal data.
2. The source takes a consistent initial snapshot with an accompanying change cursor and emits these snapshots using its existing record revisions.
3. The source replays changes after that cursor. Snapshot and change deliveries use this same revision-safe endpoint; replaying older snapshot rows cannot regress newer records.
4. Source systems save business changes and pending deliveries atomically in their own transactional outbox, then retry until acknowledged. DMS cannot install an outbox inside another group's database.
5. Reconcile expected counts/revisions periodically; the source owns its export checkpoint. Store credentials securely and use HTTPS outside localhost.

If the source API lacks a consistent snapshot/change cursor, historical revisions or deleted-record evidence, a complete lossless import cannot be promised. An API URL alone is insufficient. Pull adapters, source outboxes, scheduled reconciliation and an import-management UI are not implemented here because the other groups' contracts do not yet exist.

## Production work remaining

Define and validate per-source payload schemas and field grants before real data; current payload intake accepts generic JSON within the configured source scope. Approve retention/disposal and privacy controls for history. Provision encryption, source credential rotation and a production database, then test recovery of both new tables. This working synchronization slice does not make the entire DMS production ready.
