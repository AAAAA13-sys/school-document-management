# PLP Document Management System (DMS)

## Enterprise API Connector & Integration Documentation

The **Document Management System (DMS)** serves as the central, immutable document custody and integration hub for the **Pamantasan ng Lungsod ng Pasig (PLP)** Enterprise Systems Architecture.

DMS manages tamper-evident document storage, cryptographic SHA-256 integrity verification, automated anti-malware scanning, version tracking, and authorized cross-system document exchange between all four campus enterprise systems:

- **OAS** — Online Admission System
- **ES** — Enrollment System
- **EMS** — Employee Management System
- **PMS** — Payroll Management System

---

## 1. Integration Boundary & System Topology

DMS enforces a **Single Integration Boundary**:

- **Zero Shared Databases:** External ERPs never touch the DMS database directly.
- **Dedicated Scoped Token:** Each system receives an isolated, cryptographically hashed API token bound strictly to its source identity, school, and campus.
- **RESTful API Gateway:** All data exchange flows through `/api/v1/` using JSON and standard `multipart/form-data`.
- **Anti-Malware Gate:** All incoming documents enter a `Pending` state and are queued for ClamAV scanning before becoming downloadable.

```mermaid
flowchart TD
    subgraph EXTERNAL_ERPS["PLP Campus Enterprise Systems"]
        OAS["OAS (Online Admissions)"]
        ES["ES (Enrollment System)"]
        EMS["EMS (Employee Management)"]
        PMS["PMS (Payroll Management)"]
    end

    subgraph DMS_GATEWAY["DMS Integration Gateway (/api/v1)"]
        AUTH["Bearer Token Authenticator<br/>(120 req/min · Scoped Isolation)"]
        IDEMP["Idempotency & Replay Engine<br/>(SHA-256 Canonical Hashing)"]
        ROUTER["API Route Controller"]
    end

    subgraph DMS_CORE["DMS Core Services"]
        VAULT[("Immutable Document Vault<br/>(SHA-256 Integrity)")]
        SCANNER["ClamAV Security Scanner<br/>(Pending → Clean / Quarantined)"]
        AUDIT[("Append-Only History Stream<br/>(Event Ingestion)")]
        PACKETS["OAS ↔ ES Verified Packet Broker"]
    end

    OAS -->|"POST /documents<br/>POST /applicant-packets"| AUTH
    ES -->|"GET /applicant-packets<br/>GET /files/{id}"| AUTH
    EMS -->|"POST /documents<br/>POST /history"| AUTH
    PMS -->|"POST /documents<br/>POST /history"| AUTH

    AUTH --> IDEMP --> ROUTER
    ROUTER --> VAULT
    ROUTER --> AUDIT
    ROUTER --> PACKETS
    VAULT -.-> SCANNER
```

---

## 2. Quickstart for ERP Teams (5-Minute Integration)

### Base URLs

- **Local Development:** `http://127.0.0.1:8000/api/v1`
- **Class / Staging Network:** `http://<DMS_HOST>:<PORT>/api/v1`

### Pre-Seeded Development Tokens

For local testing, standard development tokens are pre-seeded in the database:

| System                         | Source Code | Pre-Seeded API Bearer Token |
| :----------------------------- | :---------: | :-------------------------- |
| **Online Admission System**    |    `OAS`    | `dms_dev_oas_token_2026`    |
| **Enrollment System**          |    `ES`     | `dms_dev_es_token_2026`     |
| **Employee Management System** |    `EMS`    | `dms_dev_ems_token_2026`    |
| **Payroll Management System**  |    `PMS`    | `dms_dev_pms_token_2026`    |

### Required HTTP Headers

Include these headers in every API request:

```http
Authorization: Bearer <YOUR_CONNECTOR_TOKEN>
Accept: application/json
```

_(Alternatively, you may pass `X-API-Key: <YOUR_CONNECTOR_TOKEN>`)_.

### Configuring Your ERP's `.env`

In your system's `.env` configuration file, add:

```dotenv
DMS_BASE_URL="http://127.0.0.1:8000/api/v1"
DMS_API_TOKEN="dms_dev_oas_token_2026"
```

---

## 3. Managing Connector Tokens

### Option A: Via the Web Admin Panel (Visual UI)

1. Navigate to `http://127.0.0.1:8000/login` and sign in as Super Admin (`dms.admin@plpasig.edu.ph`).
2. Open **ERP connectors** (`/admin/connectors`).
3. Click **Add connector**, select your source (`OAS`, `ES`, `EMS`, or `PMS`), enter a connection name, and submit.
4. Copy the generated 64-character token. Each row also includes **Show token**, **Update**, and **Delete** actions.

### Option B: Via Artisan CLI

Run this command on the DMS server to generate or rotate a connector token:

```bash
# Generate a new random token for ES
php artisan dms:connector ES

# Or set a specific known token
php artisan dms:connector OAS --token="my_custom_secure_token_here"
```

---

## 4. API Reference for ERP Adapters

### A. Document Ingestion (`POST /documents`)

Upload a new supporting document into the DMS vault.

- **Method:** `POST /api/v1/documents`
- **Content-Type:** `multipart/form-data`
- **Headers:**
  - `Authorization: Bearer <TOKEN>`
  - `Idempotency-Key: <UNIQUE_UUID_OR_STRING>` _(Required: 1–100 characters; prevents accidental duplicate uploads)_

#### Form Fields:

| Field               | Type        | Required | Description                                                               |
| :------------------ | :---------- | :------: | :------------------------------------------------------------------------ |
| `file`              | File Binary | **Yes**  | Up to 10 MB. Accepts all formats (PDF, PNG, JPG, DOCX, etc.).             |
| `title`             | String      | **Yes**  | Human-readable document title (1–200 chars).                              |
| `classification`    | String      | **Yes**  | `Internal`, `Confidential`, or `Restricted`.                              |
| `subject`           | String      |    No    | Document topic or student/employee identifier (max 200 chars).            |
| `reference`         | String      |    No    | External ERP filing code or receipt number (max 100 chars).               |
| `confirm_duplicate` | Boolean     |    No    | Pass `true` if intentionally uploading a file that already exists in DMS. |

#### Success Response (`201 Created`):

```json
{
  "document_id": "9d9016b8-2a29-4d6b-80df-9db51d02c5c1",
  "version_id": "f8a9c27e-3990-48e2-9bca-864b22c7102a",
  "revision": 1,
  "scan_status": "Pending"
}
```

> **Idempotent Replay Guarantee:** If your ERP encounters a network timeout and resends the exact same request with the same `Idempotency-Key`, DMS returns `200 OK` with the original receipt without creating a second file.

---

### B. Append New Version (`POST /documents/{document}/versions`)

Upload an updated version of an existing document while preserving the complete audit history.

- **Method:** `POST /api/v1/documents/{document_id}/versions`
- **Content-Type:** `multipart/form-data`
- **Form Fields:**
  - `file`: New binary file (max 10 MB).
  - `revision`: The expected current revision number (optimistic locking to prevent race conditions).
  - `submission_key`: Unique string identifier for retry safety.

---

### C. Retrieve Document Metadata (`GET /documents/{document}`)

Get current document status, active version, classification, and virus scanning verdict.

- **Method:** `GET /api/v1/documents/{document_id}`
- **Response (`200 OK`):**

```json
{
  "id": "9d9016b8-2a29-4d6b-80df-9db51d02c5c1",
  "title": "Grade 12 Report Card (Form 138)",
  "source": "OAS",
  "classification": "Internal",
  "revision": 1,
  "scan_status": "Clean",
  "versions": [
    {
      "version_id": "f8a9c27e-3990-48e2-9bca-864b22c7102a",
      "version_number": 1,
      "size_bytes": 142857,
      "mime": "application/pdf",
      "scan_status": "Clean",
      "download_url": "/api/v1/files/f8a9c27e-3990-48e2-9bca-864b22c7102a"
    }
  ]
}
```

---

### D. Download File (`GET /files/{version_id}`)

Stream the verified file binary.

- **Method:** `GET /api/v1/files/{version_id}`
- **Rules:**
  - If the file is `Clean`: Returns `200 OK` with `Content-Disposition: attachment`.
  - If the file is `Pending` scan: Returns `423 Locked`.
  - If the file failed scanning or was quarantined: Returns `423 Locked` (blocked from circulation).

---

### E. Time-Limited Signed Stream URL (`GET /documents/{document}/download-url`)

Generate a safe, signed temporary URL suitable for embedding in an ERP user interface or preview iframe without exposing long-lived connector tokens.

- **Method:** `GET /api/v1/documents/{document_id}/download-url`
- **Response (`200 OK`):**

```json
{
  "download_url": "http://127.0.0.1:8000/api/v1/files/stream/f8a9c27e-3990-48e2-9bca-864b22c7102a?expires=1793500000&signature=d9a8f..."
}
```

---

### F. History Audit Event Ingestion (`POST /history`)

Record immutable transactions and business state events from your ERP into the permanent school audit log.

- **Method:** `POST /api/v1/history`
- **Content-Type:** `application/json`

#### Request Body:

```json
{
  "event_id": "oas:applicant:APP-2026-0819:1",
  "record_type": "applicant",
  "record_id": "APP-2026-0819",
  "revision": 1,
  "operation": "upsert",
  "payload": {
    "applicant_id": "APP-2026-0819",
    "name": "Maria Clara Santos",
    "strand": "STEM",
    "status": "Qualified for Enrollment"
  }
}
```

#### Field Specifications:

- `event_id`: Unique idempotency key (max 100 chars).
- `record_type`: Entity type (e.g., `applicant`, `student`, `employee`, `payroll_entry`).
- `record_id`: Primary identifier from the source ERP.
- `revision`: Monotonically increasing integer (1, 2, 3...).
- `operation`: `upsert` or `delete`.
- `payload`: Arbitrary JSON object containing event evidence (max 256 KiB). Key order is canonically normalized.

---

### G. Incremental Read-Back with Cursors (`GET /history`, `/documents`, `/files`)

Synchronize changes into your ERP using high-performance cursor pagination:

- **Endpoint:** `GET /api/v1/{resource}?after=0&limit=50`
- **Response (`200 OK`):**

```json
{
  "data": [...],
  "next_cursor": 150,
  "has_more": true
}
```

_Process each batch, save `next_cursor`, and request `?after={next_cursor}` until `has_more` is `false`._

---

## 5. Seamless Admission & Enrollment Handoff (OAS ↔ ES)

DMS enables a zero-friction, verified bridge between **Online Admissions (OAS)** and the **Enrollment System (ES)** without allowing unauthorized cross-system database writes:

```mermaid
sequenceDiagram
    autonumber
    actor Student as Applicant
    participant OAS as OAS (Admissions)
    participant DMS as DMS Vault
    participant ES as ES (Enrollment)

    Student->>OAS: Submits Admission Documents (Form 138, PSA Birth Cert)
    OAS->>DMS: POST /api/v1/documents (Stores PSA & Form 138)
    Note over DMS: ClamAV Scans Files → Verdict: Clean
    OAS->>OAS: Admissions Team Verifies & Approves Applicant

    rect rgb(240, 248, 240)
        Note over OAS,DMS: 1. OAS Releases Verified Packet to DMS
        OAS->>DMS: POST /api/v1/applicant-packets<br/>{applicant_id: "APP-001", admitted: true, verified: true, version_ids: [...]}
        DMS-->>OAS: 201 Created (Packet ID: PKT-001)
    end

    rect rgb(240, 244, 255)
        Note over DMS,ES: 2. ES Ingests Verified Documents
        ES->>DMS: GET /api/v1/applicant-packets/PKT-001
        DMS-->>ES: 200 OK (Packet Metadata & Allowed Version UUIDs)
        ES->>DMS: GET /api/v1/applicant-packets/PKT-001/files/{version_id}
        DMS-->>ES: 200 OK (Clean Binary File Stream)
        ES->>ES: Enrolls Student into Academic Database
    end
```

### 1. OAS Packet Publication:

```http
POST /api/v1/applicant-packets
Content-Type: application/json
Authorization: Bearer dms_dev_oas_token_2026

{
  "event_id": "oas:admit:APP-2026-001:1",
  "applicant_id": "APP-2026-001",
  "revision": 1,
  "released": true,
  "admitted": true,
  "verified": true,
  "version_ids": [
    "f8a9c27e-3990-48e2-9bca-864b22c7102a",
    "b3f71c42-7721-4d32-aa09-1e35d8ef4920"
  ]
}
```

### 2. ES Verified Packet Retrieval:

```http
GET /api/v1/applicant-packets/PKT-APP-2026-001
Authorization: Bearer dms_dev_es_token_2026
```

### 3. ES Packet Document Download:

```http
GET /api/v1/applicant-packets/PKT-APP-2026-001/files/f8a9c27e-3990-48e2-9bca-864b22c7102a
Authorization: Bearer dms_dev_es_token_2026
```

---

## 6. HTTP Status Codes & Error Handling

|       Status Code       | Meaning                            | Cause / Resolution                                                                                                                                                                                |
| :---------------------: | :--------------------------------- | :------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
|      **`200 OK`**       | Success / Idempotent Replay        | Request processed, or identical previous submission safely returned.                                                                                                                              |
|    **`201 Created`**    | Created                            | New document, version, history event, or packet successfully accepted.                                                                                                                            |
|  **`400 Bad Request`**  | Invalid Syntax                     | Missing mandatory fields or invalid parameters.                                                                                                                                                   |
| **`401 Unauthorized`**  | Authentication Error               | Missing or invalid Bearer token. Check your token against `/admin/connectors`.                                                                                                                    |
|   **`403 Forbidden`**   | Scope Restriction                  | Connector token is inactive or attempted to access an out-of-scope source.                                                                                                                        |
|   **`409 Conflict`**    | Concurrency / Idempotency Conflict | **A:** A reused `Idempotency-Key` was sent with altered content.<br/>**B:** Optimistic locking revision mismatch.<br/>**C:** Duplicate file detected. _(Pass `confirm_duplicate=true` to force)_. |
| **`422 Unprocessable`** | Validation Error                   | File exceeds 10 MB, invalid classification, or invalid data types.                                                                                                                                |
|    **`423 Locked`**     | Security Gate Engaged              | File is currently `Pending` virus scan, or failed / quarantined.                                                                                                                                  |
| **`429 Too Many Req`**  | Rate Limited                       | Exceeded 120 requests/minute. Respect the `Retry-After` header.                                                                                                                                   |
|     **`500 / 503`**     | Server / Queue Error               | Database or storage issue. Safe to retry with exponential backoff.                                                                                                                                |

---

## 7. Code Implementation Examples for External ERPs

### cURL

```bash
# 1. Upload a document
curl -X POST "http://127.0.0.1:8000/api/v1/documents" \
  -H "Authorization: Bearer dms_dev_oas_token_2026" \
  -H "Accept: application/json" \
  -H "Idempotency-Key: req-oas-form138-001" \
  -F "title=Applicant Form 138" \
  -F "classification=Internal" \
  -F "subject=APP-2026-001" \
  -F "file=@/path/to/form138.pdf"

# 2. Record an audit history event
curl -X POST "http://127.0.0.1:8000/api/v1/history" \
  -H "Authorization: Bearer dms_dev_ems_token_2026" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "event_id": "ems:evt:001",
    "record_type": "employee",
    "record_id": "EMP-102",
    "revision": 1,
    "operation": "upsert",
    "payload": {"department": "College of Computer Studies", "active": true}
  }'
```

### PHP (Laravel HTTP Client)

```php
use Illuminate\Support\Facades\Http;

$response = Http::withToken(config('services.dms.token'))
    ->withHeaders([
        'Accept' => 'application/json',
        'Idempotency-Key' => (string) Str::uuid(),
    ])
    ->attach('file', file_get_contents($filePath), 'student_record.pdf')
    ->post(config('services.dms.url') . '/documents', [
        'title' => 'Official Transcript of Records',
        'classification' => 'Internal',
        'subject' => 'STUDENT-2026-0042',
    ]);

if ($response->successful()) {
    $dmsDocumentId = $response->json('document_id');
}
```

### JavaScript / Node.js (Axios)

```javascript
const axios = require("axios");
const FormData = require("form-data");
const fs = require("fs");

const form = new FormData();
form.append("title", "Faculty Clearance Form");
form.append("classification", "Internal");
form.append("file", fs.createReadStream("./clearance.pdf"));

const response = await axios.post("http://127.0.0.1:8000/api/v1/documents", form, {
  headers: {
    ...form.getHeaders(),
    Authorization: "Bearer dms_dev_ems_token_2026",
    Accept: "application/json",
    "Idempotency-Key": "upload-clearance-emp-089",
  },
});

console.log("Stored in DMS with UUID:", response.data.document_id);
```

### Python (Requests)

```python
import requests

url = "http://127.0.0.1:8000/api/v1/documents"
headers = {
    "Authorization": "Bearer dms_dev_pms_token_2026",
    "Accept": "application/json",
    "Idempotency-Key": "payroll-2026-11-remittance",
}
data = {
    "title": "Monthly GSIS Remittance Report",
    "classification": "Confidential",
    "subject": "PAYROLL-2026-NOV",
}
files = {
    "file": open("gsis_remittance_nov2026.pdf", "rb")
}

resp = requests.post(url, headers=headers, data=data, files=files)
print(resp.status_code, resp.json())
```

---

## 8. Testing & Architecture Validation

- **Postman Collection:** Ready-to-import integration test suite located at [`postman/DMS-ERP-v1.postman_collection.json`](postman/DMS-ERP-v1.postman_collection.json).
- **OpenAPI 3.0 Specification:** Complete schema definitions located at [`openapi.json`](openapi.json).
- **Automated Test Suite:** DMS includes 262 backend tests verifying token isolation, ClamAV fail-closed gates, race conditions, and replay protection:
  ```bash
  # Execute automated test suite
  php vendor/pestphp/pest/bin/pest
  ```

---

## 9. Monorepo Repository Structure

| Directory / File                                     | Description                                    |
| :--------------------------------------------------- | :--------------------------------------------- |
| `app/Http/Controllers/ApiController.php`             | Main `/api/v1` gateway controller.             |
| `app/Http/Controllers/ApplicantPacketController.php` | OAS ↔ ES verified packet broker.               |
| `app/Http/Middleware/ConnectorAuth.php`              | Scoped token authenticator & rate-limiter.     |
| `app/Services/DocumentService.php`                   | Document vault storage and versioning engine.  |
| `app/Services/HistoryService.php`                    | Canonical audit event hashing and persistence. |
| `docs/ERP-CONNECTIONS-GUIDE.md`                      | Beginner-friendly integration walkthrough.     |
| `docs/API-STANDARD.md`                               | Enterprise Architecture integration standards. |
| `docs/ESMS-API-CATALOG.md`                           | Complete cross-ERP endpoint directory.         |
