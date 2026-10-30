# School Systems API Standard

## Scope

Applies to every app-to-app API among: **EMS**, **TAP-PMS**, **Admission**, **DMS**, and any future system. It does not cover browser pages or human logins (those stay each app's choice: Fortify, sessions or JWT). Stack is Laravel; most rules match Laravel defaults.

## The four rules that can't change later

1. `Authorization: Bearer <token>`, one token per calling app.
2. Official IDs: other apps store the owner's ID and never invent their own.
3. Response shape: `data`, `meta`, Laravel error format.
4. `/api/v1` in every URL.

## URLs and versions

- Pattern: `https://<app-host>/api/v1/<resource>[/<id>][/<sub-resource>]`
- Resource names: lowercase, plural, hyphenated (`/employees`, `/job-titles`, `/applicant-packets`).
- Not breaking: new endpoint, new response field, new optional parameter. Callers must ignore unknown fields.
- Breaking: removing or renaming a field, changing its type or meaning, making an optional parameter required. Breaking changes require a new version (`/api/v2`).

## Authentication (app to app)

- Headers on every request: `Authorization: Bearer <token>`, `Accept: application/json`, `Content-Type: application/json`.
- Admission calling DMS and EMS holds one token for each.
- Store tokens hashed (e.g. SHA-256). Show a token once, at creation.
- Permissions are named `<resource>:<action>` (`employees:read`, `documents:write`); grant the minimum. EMS already works this way.
- Tokens are individually revocable, and last use is recorded.
- HTTPS everywhere except `localhost`. Never put tokens in URLs, logs or error messages.
- Missing, unknown or revoked token: `401` with `WWW-Authenticate: Bearer`. Valid token without the permission: `403`.

## Responses and errors

- Results go in `data`; the only other top-level key is `meta`. No `"status": "success"`; the HTTP status says it.

```json
{
  "data": [{ "employee_no": "26-00001", "status": "active" }],
  "meta": { "next_cursor": "eyJpZCI6MTIzfQ", "has_more": true }
}
```

- Errors: `message` always, `errors` for validation, optional machine-readable `code` for business rules.

```json
{ "message": "Applications for this program are closed.", "code": "application_closed" }
```

- Never return stack traces, SQL or file paths. `APP_DEBUG=false` outside local machines.

| Code      | Use                                                          |
| --------- | ------------------------------------------------------------ |
| 200       | Read or update worked                                        |
| 201       | Created; return it in `data`                                 |
| 202       | Accepted, finishes later (e.g. virus scan)                   |
| 204       | Deleted, nothing to return                                   |
| 207       | Bulk request, some items failed                              |
| 400       | Malformed request                                            |
| 401 / 403 | Not authenticated / not allowed                              |
| 404       | Not found, or caller may not know it exists                  |
| 409       | Conflict (e.g. reused `Idempotency-Key` with different body) |
| 410       | Gone for good                                                |
| 422       | Validation error or broken business rule                     |
| 429       | Rate limited; send `Retry-After`                             |
| 500 / 503 | Server error / unavailable; caller may retry                 |

## Paging, sync, filters

- Cursor paging: `GET /api/v1/employees?limit=50&cursor=<opaque>`. `limit` default 50, max 100.
- Pass `meta.next_cursor` back as-is; never build one. When `meta.has_more` is `false`, `next_cursor` is `null`. In Laravel use `->cursorPaginate()`.
- Lists other apps copy (employees, applicants, documents) accept `?updated_since=<ISO 8601>`, sorted by `updated_at` then ID.
- Filters use the field name: `?status=active&college=CCS`.

## Data conventions

| Item            | Rule                                                          | Example                            |
| --------------- | ------------------------------------------------------------- | ---------------------------------- |
| Field names     | `snake_case`                                                  | `employee_no`, `hired_on`          |
| Dates           | `YYYY-MM-DD`                                                  | `"2026-10-30"`                     |
| Timestamps      | ISO 8601 with time zone, **stored and sent in UTC** (Decided) | `"2026-10-30T06:05:00Z"`           |
| Money           | String, two decimals; no floats in payroll math               | `"35000.00"`                       |
| Booleans        | `true` / `false` only                                         | `"is_on_leave": false`             |
| Enums           | lowercase `snake_case` strings                                | `"on_leave"`                       |
| Missing values  | `null`, field kept                                            | `"office": null`                   |
| Related records | Small object with ID and name                                 | `{ "code": "CCS", "name": "..." }` |

## Official IDs and ownership

Each record has one owning system, whose ID is the official one. IDs never change. Don't expose auto-increment DB IDs when an official ID exists.

| Record                  | Owner                 | Official ID                     |
| ----------------------- | --------------------- | ------------------------------- |
| Employee                | EMS                   | `employee_no` (e.g. `26-00001`) |
| Applicant / application | Admission             | Applicant reference number      |
| Document and version    | DMS                   | UUID                            |
| Payroll period, payslip | **TAP-PMS** (Decided) | Format still to be agreed       |

## Writes, retries, files, logging

- Every `POST` sends `Idempotency-Key` (new UUID). Same key + same body returns the original response; same key + different body returns `409`. Keep keys at least 24 hours.
- On network error, `429` or `5xx`: retry with the same key, backing off 1 s, 4 s, 16 s.
- Bulk requests return `207` with one result per item under `data`: `{ "index": 0, "status": 201, "data": {...} }` or `{ "index": 1, "status": 422, "error": {...} }`.
- Files: upload as `multipart/form-data`; downloads send the right `Content-Type` and a `Content-Disposition` file name.
- Rate limit per token (`429` + `Retry-After`).
- Log every inbound call: caller, method, path, status, time. Never log tokens, passwords or file contents.
- Optional: accept and forward `X-Request-Id` for tracing.

## Building a new API

1. The owning team writes the endpoint in `docs/openapi.json` (OpenAPI 3): permission, example request/response, error codes.
2. Owner builds it; callers build against the agreed contract, not a guessed mock.
3. Every later API change updates `openapi.json` in the same PR.

Review checklist for each endpoint:

- [ ] Under `/api/v1`, plural hyphenated resource name
- [ ] Bearer token with named permission; correct `401`/`403`
- [ ] Result in `data`, extras in `meta`, no `status` field
- [ ] Laravel error format; business rules return `422` with `code`
- [ ] Cursor paging; synced lists accept `updated_since`
- [ ] `snake_case`, ISO dates, UTC timestamps, money as strings, official IDs
- [ ] `POST` honors `Idempotency-Key`
- [ ] Rate limited and logged, no secrets in logs
- [ ] `openapi.json` updated

## Per-app work (done in each app's original repo, not the monorepo)

| App       | Change                                                                                                                     | Effort       |
| --------- | -------------------------------------------------------------------------------------------------------------------------- | ------------ |
| Admission | Fix DMS client: send `Authorization: Bearer` to `/api/v1/applicant-packets` (currently `X-API-Key` to a nonexistent route) | Small-Medium |
| Admission | Add `/api/v1` prefix; accept Bearer instead of `X-API-Key`; move extras like `verified` into `meta`; add `openapi.json`    | Small        |
| DMS       | Move `next_cursor`/`has_more` into `meta`; return bulk results under `data`                                                | Small        |
| EMS       | Cursor paging; money as strings; add `openapi.json`                                                                        | Medium       |
| EMS       | Add the payroll-relevant endpoints TAP-PMS needs (see below)                                                               | Medium       |
| TAP-PMS   | Drop `status: "success"`; `snake_case` fields; use `employee_no` instead of `EMP-001`                                      | Small        |
| TAP-PMS   | Build a real backend that follows this standard; issue payroll IDs                                                         | Large        |

## Resolved decisions (from the doc's open questions)

1. **Payroll ownership: TAP-PMS owns payroll IDs** (payroll period, payslip). Not confirmed in the answer: where payslip _files_ are stored. If they live in DMS, DMS keeps the file UUID and stores TAP-PMS's payslip ID as a reference, never its own payroll ID.
2. **EMS -> TAP-PMS data: only what is relevant to payroll.** EMS shares salary changes and employee history limited to payroll needs, via `employees:read`-style scoped permissions (e.g. a payroll-specific permission). Keep other employee data out of the TAP-PMS token's scope. The exact field list goes in EMS's `openapi.json` and is reviewed by TAP-PMS.
3. **Time zone: UTC** for all stored and exchanged timestamps. Convert to local time (e.g. Asia/Manila, +08:00) only at display. Plain dates (`YYYY-MM-DD`) are unaffected.
4. **Old API paths after a version change (Recommended, pending sign-off):**
   - Keep the old version running at least **90 days** after the new version ships, and until every caller has moved, whichever is later (the doc's existing rule).
   - Mark old endpoints with `Deprecation` and `Sunset` response headers from the day the new version ships.
   - Use the existing per-token "last use" and call logs to see who still calls the old version; remove it only when that list is empty.
   - After sunset, return `410` with a `message` pointing to the new path.
   - With only four internal teams, this is simple to coordinate; it also bounds how long two versions must be maintained.

## Guidance for an agent working in this codebase

- Treat the four settled rules as non-negotiable; everything else is additive.
- Before adding an integration, check the owner table above; don't create a second ID for a record someone else owns.
- If a task touches payroll money, use string decimals and never floats.
- Use UTC for any new timestamp field.
- New or changed endpoint = update `docs/openapi.json` in the same PR.
- Where this brief and `API-STANDARD.md` disagree, the monorepo file wins; flag the mismatch.
