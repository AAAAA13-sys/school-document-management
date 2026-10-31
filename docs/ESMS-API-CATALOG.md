# BSIT3B-ESMS Enterprise API Integration Catalog & Guide

This catalog documents the API endpoints across the **BSIT3B-ESMS** organization (`https://github.com/BSIT3B-ESMS`), specifically within `BSIT3B-ESMS/Enterprise-School-Management-System`. It provides the exact contracts, authentication protocols, request/response formats, and architectural patterns required for the Document Management System (DMS) to seamlessly connect with all systems.

---

## 1. System Ecosystem Overview

| System      | Role / Capability                                     | Primary Monorepo App       | Auth Mechanism                            | Base URL (Configurable)                        |
| ----------- | ----------------------------------------------------- | -------------------------- | ----------------------------------------- | ---------------------------------------------- |
| **EMS**     | Employee Management System (Faculty & Staff Roster)   | `apps/ems`                 | Bearer Token (`api.token:employees:read`) | `EMS_API_URL` (e.g. `http://ems.local/api/v1`) |
| **OAS**     | Online Admission System (Applicant records & packets) | `apps/admission`           | API Key (`X-API-Key`) + JWT (`auth:api`)  | `OAS_API_URL` (e.g. `http://oas.local/api`)    |
| **TAP-PMS** | Payroll & Attendance Management System                | `apps/tap-pms`             | Bearer Token (Sanctum / Scoped token)     | `PMS_API_URL` (e.g. `http://pms.local/api`)    |
| **DMS**     | Document Management System (Central Archive & Memos)  | `apps/document-management` | Bearer Connector Token (`token_hash`)     | `APP_URL/api/v1`                               |

---

## 2. EMS (Employee Management System) Endpoints

### Authentication

- Header: `Authorization: Bearer <EMS_API_TOKEN>`
- Scopes: `employees:read`, `reference:read`

### Endpoints

#### 2.1. List Employees

- **HTTP Method:** `GET`
- **Route:** `/api/v1/employees`
- **Query Parameters:**
  - `page` (integer, optional, default: 1)
  - `limit` (integer, optional, default: 25, max: 100)
  - `status` (string, optional: `active`, `inactive`, `on_leave`, `resigned`)
  - `college_id` (integer, optional)
  - `job_title_id` (integer, optional)
- **Response `200 OK`:**

```json
{
  "success": true,
  "data": [
    {
      "employee_no": "23-00142",
      "first_name": "Maria",
      "last_name": "Santos",
      "email": "maria.santos@plpasig.edu.ph",
      "college": "College of Computer Studies",
      "job_title": "Assistant Professor I",
      "status": "active",
      "school_id": "PLP",
      "campus": "Main"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 10,
    "total": 245
  }
}
```

#### 2.2. Show Employee Profile

- **HTTP Method:** `GET`
- **Route:** `/api/v1/employees/{employeeNo}`
- **Route Parameter:** `employeeNo` format: `^[0-9]{2}-[0-9]{5}$` (e.g. `23-00142`)
- **Response `200 OK`:**

```json
{
  "success": true,
  "data": {
    "employee_no": "23-00142",
    "first_name": "Maria",
    "middle_name": "Clara",
    "last_name": "Santos",
    "suffix": null,
    "email": "maria.santos@plpasig.edu.ph",
    "phone": "+639171234567",
    "department": "Information Technology",
    "college_id": 3,
    "college": "College of Computer Studies",
    "job_title_id": 12,
    "job_title": "Assistant Professor I",
    "rate_tier_id": 4,
    "status": "active",
    "hired_at": "2023-08-15"
  }
}
```

#### 2.3. Check Employment Status

- **HTTP Method:** `GET`
- **Route:** `/api/v1/employees/{employeeNo}/status`
- **Response `200 OK`:**

```json
{
  "success": true,
  "employee_no": "23-00142",
  "status": "active",
  "is_active": true,
  "last_updated": "2026-10-31T08:00:00Z"
}
```

#### 2.4. Reference Lists (Colleges & Job Titles)

- **HTTP Method:** `GET /api/v1/colleges`
- **HTTP Method:** `GET /api/v1/job-titles`
- **HTTP Method:** `GET /api/v1/rate-tiers`
- **Response `200 OK`:**

```json
{
  "success": true,
  "data": [
    { "id": 1, "code": "CCS", "name": "College of Computer Studies" },
    { "id": 2, "code": "COE", "name": "College of Education" },
    { "id": 3, "code": "CBA", "name": "College of Business Administration" }
  ]
}
```

---

## 3. OAS (Online Admission System — `hanimehub06-eng/Admission_System`)

The Online Admission System (OAS) manages the end-to-end applicant journey for Pamantasan ng Lungsod ng Pasig (PLP). It interfaces directly with the Document Management System (DMS) as an authoritative source for applicant dossiers, document packets, and audit history.

### 3.1. OAS ↔ DMS Direct Integration Architecture

The OAS interacts with the DMS through an outbox pattern (`dms_outbox` table, processed asynchronously by `DeliverDmsEvent` queue jobs with exponential backoff and idempotency protection).

```
 ┌─────────────────────────┐              ┌─────────────────────────┐
 │   OAS (Admission)       │              │    DMS (Document System)│
 │                         │              │                         │
 │ 1. Upload File          │ POST /docs   │ Store & scan file       │
 │    (DmsDocumentStore)   ├─────────────►│ Return document_id &    │
 │                         │◄─────────────┤   version_id UUIDs      │
 │                         │              │                         │
 │ 2. Lifecycle Events     │ POST /history│ Append to audit trail   │
 │    (DmsSync outbox)     ├─────────────►│ & source_snapshots      │
 │                         │              │                         │
 │ 3. Applicant Packet     │ POST /packets│ Create sealed packet &  │
 │    (on admission clear) ├─────────────►│ release verified docs   │
 │                         │              │                         │
 │ 4. File Retrieval       │ GET /files/{v│ Verify scan Clean &     │
 │    (stream proxy)       ├─────────────►│ stream file binary      │
 └─────────────────────────┘              └─────────────────────────┘
```

#### Authentication & Headers

- **Authentication:** `Authorization: Bearer <DMS_TOKEN>` (token corresponding to the OAS connector in DMS).
- **Idempotency:** `Idempotency-Key: <UUID>` header sent on every POST mutation.
- **Base URL:** `https://<dms-host>/api/v1`

#### A. Document Storage (`DmsDocumentStore`)

- **Initial Upload:**
  - **Method:** `POST /api/v1/documents`
  - **Payload (multipart/form-data):**
    - `file`: Raw binary (PDF, JPG, PNG up to 5 MB)
    - `title`: `"{requirement.title} · {application.number}"`
    - `classification`: `"Confidential"`
    - `reference`: `"{application.number}"` (e.g. `AD-2026-00001`)
    - `category`: `"{requirement.key}"` (e.g. `birthCert`, `form137`, `goodMoral`)
  - **Response `201 Created`:**
    ```json
    {
      "document_id": "9d3e8b42-17a4-4f51-b0e2-632145e10091",
      "version_id": "c1f72a91-4402-4829-9e8a-7512d483b802"
    }
    ```
  - **Storage Reference in OAS:** Formatted as `"{document_id}:{version_id}"`.
- **Version Replacement (after resubmission request):**
  - **Method:** `POST /api/v1/documents/{document_id}/versions`
  - **Payload:** `file` multipart binary + `revision` (integer fetched from `GET /api/v1/documents/{document_id}`).
- **File Streaming / Download:**
  - **Method:** `GET /api/v1/files/{version_id}`
  - **Behavior:** DMS verifies malware scan status (`Clean`) and checksum; returns HTTP 423 while scanning is pending; returns binary stream when clean.
- **Confidentiality Rule:** Medical files (`xray`, `cbc`, `urinalysis`, `feces`, etc.) are classified as medical secrets and **never** sent to the DMS. They are stored locally on the OAS clinic storage driver.

#### B. Lifecycle Event Ingestion (`DmsSync` Outbox)

- **Method:** `POST /api/v1/history`
- **Request Body:**
  ```json
  {
    "event_id": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
    "record_type": "applicant",
    "record_id": "AD-2026-00001",
    "revision": 1,
    "operation": "upsert",
    "payload": {
      "applicant_ref": "AD-2026-00001",
      "admission_period": "AY 2026-2027",
      "status": "for_exam",
      "lrn": "123456789012",
      "last_name": "Mendoza",
      "first_name": "Jordan",
      "middle_name": "Reyes",
      "sex": "Male",
      "birthday": "2008-03-14",
      "email": "jordan@example.com",
      "phone": "+63 917 123 4567",
      "address": "12 Mabini St., Pasig City",
      "is_resident": true,
      "district": 1,
      "barangay": "Kapitolyo",
      "last_school": "Rizal High School",
      "school_type": "Public",
      "strand": "STEM",
      "applicant_type": "Freshman",
      "program_choices": [
        { "rank": 1, "code": "BSIT" },
        { "rank": 2, "code": "BSCS" }
      ],
      "offered_program": null,
      "submitted_at": "2026-10-30T08:00:00Z",
      "admitted": false,
      "admitted_at": null
    }
  }
  ```
- **Lifecycle Event Types:**
  - `applicant.upserted`: Full applicant profile snapshot.
  - `status.changed`: Transition between pipeline states (`draft` → `submitted` → `under_review` → `for_exam` → `passed_exam` → `offered` → `admitted`).
  - `document.uploaded` / `document.reviewed` / `document.photocopy` / `document.flagged`.
  - `exam.scheduled` / `exam.attendance` / `exam.result` (scores are flagged Restricted).
  - `interview.recorded` (grades are flagged Restricted).
  - `offer.accepted` / `offer.forfeited` / `admission.completed`.

#### C. Sealed Applicant Packets (`packet.released`)

Once an applicant completes medical clearance and is marked `admitted`:

- **Method:** `POST /api/v1/applicant-packets`
- **Request Body:**
  ```json
  {
    "event_id": "e82b79a1-5503-4f9e-a744-83921c5b021a",
    "applicant_id": "AD-2026-00001",
    "revision": 12,
    "released": true,
    "admitted": true,
    "verified": true,
    "version_ids": ["c1f72a91-4402-4829-9e8a-7512d483b802", "d2a83b10-5513-4930-af9b-8623e594c913"]
  }
  ```
- **Packet Retrieval:** Downstream systems (Registrar / Enrollment) fetch the complete verified packet using `GET /api/v1/applicant-packets/{packet}`.

---

### 3.2. OAS System-to-System & Integration Endpoints

#### A. Staff Synchronization from EMS (`FR-30`)

- **CLI Command:** `php artisan ems:sync-staff` (scheduled nightly).
- **Mechanism:** OAS polls `GET /api/v1/employees/{employee_id}` using Bearer token authentication. If employee is inactive or deleted in EMS, the OAS account is deactivated automatically.
- **Legacy Inbound Route (`main` branch):**
  - `GET /api/integrations/employment/employees` — List synced staff accounts.
  - `POST /api/integrations/employment/employees` — Bulk sync up to 500 staff. Header: `X-API-Key: <key>`.
  - `DELETE /api/integrations/employment/employees/{employeeId}` — Deactivate employee.

#### B. Registrar & Enrollment Handshake (`FR-29`)

- **Admitted Applicants Feed:**
  - **Route:** `GET /api/integrations/registrar/admitted`
  - **Query:** `since=YYYY-MM-DDTHH:MM:SSZ&page=1`
  - **Auth:** `X-API-Key: <key>`
  - **Response:** Paged collection of admitted applicants (without exam scores).
- **Student Number Callback (Enrollment → OAS):**
  - **Route:** `POST /api/integrations/enrollment/student-number`
  - **Body:** `{ "admission_reference": "AD-2026-00001", "student_number": "2027-00123" }`
- **Modern Outbound Webhook (`api-v1-standard` branch):**
  - **Endpoint:** `POST {ENROLLMENT_URL}` (`/api/v1/admitted-applicants`)
  - **Auth:** `Authorization: Bearer <ENROLLMENT_TOKEN>`
  - **Header:** `Idempotency-Key: <UUID>`
  - **Schema:** Standardized `AdmittedApplicant` object with reference number, LRN, personal info, admitted program, and college code.

---

### 3.3. OAS Internal Domain API Routes Catalog

All routes reside under `/api`, authenticated via `Authorization: Bearer <JWT>` with 15-minute expiration and 24-hour refresh rotation.

#### Authentication (`/api/auth/*`)

| Method | Endpoint                    | Description                                                                   |
| ------ | --------------------------- | ----------------------------------------------------------------------------- |
| `POST` | `/api/auth/register`        | Applicant registration with optional Turnstile/reCAPTCHA and email code       |
| `POST` | `/api/auth/login`           | Sign-in: returns JWT or 2FA challenge (lockout: 5 failed attempts = 15m lock) |
| `POST` | `/api/auth/2fa/verify`      | Submits 6-digit email or authenticator code                                   |
| `POST` | `/api/auth/2fa/resend`      | Resends 2FA code (rate limited: 30s cooldown)                                 |
| `POST` | `/api/auth/refresh`         | Rotates expired access token inside refresh window                            |
| `POST` | `/api/auth/logout`          | Revokes current JWT token                                                     |
| `GET`  | `/api/auth/me`              | Current authenticated user profile, roles, and 2FA status                     |
| `POST` | `/api/auth/forgot-password` | Initiates password reset                                                      |
| `POST` | `/api/auth/reset-password`  | Completes password reset with token                                           |

#### Applicant Portal (`/api/applicant/*` — Role: `applicant`)

| Method   | Endpoint                              | Description                                                           |
| -------- | ------------------------------------- | --------------------------------------------------------------------- |
| `GET`    | `/api/applicant/portal`               | Full portal state: application progress, dated timeline, requirements |
| `PUT`    | `/api/applicant/application`          | Autosaves application form data (locked after submission)             |
| `PUT`    | `/api/applicant/profile`              | Updates personal contact details                                      |
| `POST`   | `/api/applicant/submit`               | Finalizes application submission for office verification              |
| `GET`    | `/api/applicant/exam-permit`          | Generates exam permit PDF with verification QR code                   |
| `GET`    | `/api/applicant/application-form`     | Generates printable application summary PDF                           |
| `GET`    | `/api/applicant/acceptance-letter`    | Generates official acceptance letter PDF once offered                 |
| `GET`    | `/api/applicant/exam-sessions`        | Lists open exam sessions (when self-scheduling enabled)               |
| `POST`   | `/api/applicant/exam-session`         | Reserves specific exam session                                        |
| `POST`   | `/api/applicant/documents/{key}`      | Uploads requirement file (PDF/JPG/PNG ≤ 5 MB)                         |
| `DELETE` | `/api/applicant/documents/{key}`      | Removes uploaded document prior to office review                      |
| `GET`    | `/api/applicant/documents/{key}/file` | Downloads applicant's own uploaded document                           |
| `POST`   | `/api/applicant/offer/accept`         | Accepts program offer before deadline                                 |
| `POST`   | `/api/applicant/medical/{key}`        | Uploads medical laboratory report                                     |
| `POST`   | `/api/applicant/medical/submit`       | Submits completed medical file to Clinic                              |

#### Admission Office Panel (`/api/admin/*` — Roles: `admission`, `admission_head`, `proctor`, `super_admin`)

| Method  | Endpoint                                               | Description                                                           |
| ------- | ------------------------------------------------------ | --------------------------------------------------------------------- |
| `GET`   | `/api/admin/state`                                     | Full state dashboard: applicant queues, roster stats, KPIs            |
| `GET`   | `/api/admin/applicants/search`                         | Live search by name, LRN, or application number                       |
| `GET`   | `/api/admin/reference`                                 | Reference lists: strands, programs, barangays                         |
| `PATCH` | `/api/admin/applicants/{no}/documents/{key}`           | Verifies document, requests resubmission, or rejects                  |
| `POST`  | `/api/admin/applicants/{no}/documents/verify-pending`  | Batch verifies all pending documents for applicant                    |
| `GET`   | `/api/admin/applicants/{no}/documents/{key}/file`      | Office viewer for applicant document                                  |
| `PATCH` | `/api/admin/applicants/{no}/documents/{key}/photocopy` | Flags physical photocopy as received                                  |
| `POST`  | `/api/admin/applicants/{no}/approve`                   | Advances applicant to `for_exam` with assigned session                |
| `POST`  | `/api/admin/exam/auto-assign`                          | Batch assigns verified applicants to earliest available exam sessions |
| `PATCH` | `/api/admin/applicants/{no}/exam`                      | Proctor updates permit check, form check, and attendance              |
| `POST`  | `/api/admin/exam/check-in`                             | Scans permit QR code for instant attendance logging                   |
| `POST`  | `/api/admin/applicants/{no}/reschedule`                | Moves applicant to a different exam session                           |
| `GET`   | `/api/admin/exam-sessions/{session}/roster`            | Roster list and attendance sheet (JSON or PDF)                        |
| `POST`  | `/api/admin/applicants/{no}/result`                    | Records entrance exam score and pass/fail flag                        |
| `POST`  | `/api/admin/results/import`                            | Bulk CSV import of exam scores                                        |
| `POST`  | `/api/admin/results/release`                           | **Admission Head only**: Publishes official results                   |
| `POST`  | `/api/admin/applicants/{no}/placement`                 | Places passed applicant in alternate program choice                   |
| `GET`   | `/api/admin/interviews`                                | Lists scheduled interviews and dean evaluations                       |
| `POST`  | `/api/admin/interview-schedules`                       | Configures interview capacity per program                             |
| `POST`  | `/api/admin/applicants/{no}/interview`                 | Schedules applicant for college dean interview                        |
| `GET`   | `/api/admin/announcements`                             | Manages admission announcements, dates, and deadlines                 |
| `GET`   | `/api/admin/accounts`                                  | Manages staff roles and tasks (`personnel`, `proctor`, `exam_office`) |

#### Clinic Module (`/api/clinic/*` — Role: `clinic`)

| Method  | Endpoint                                          | Description                                            |
| ------- | ------------------------------------------------- | ------------------------------------------------------ |
| `GET`   | `/api/clinic/state`                               | Clinic dashboard with medical review queues            |
| `GET`   | `/api/clinic/applicants/{app}/medical/{key}/file` | Clinic nurse views laboratory test result              |
| `PATCH` | `/api/clinic/applicants/{app}/medical/{key}`      | Nurse verifies medical requirement or requests re-test |
| `PATCH` | `/api/clinic/applicants/{app}/medical/{key}/flag` | Flags suspicious or falsified medical report           |
| `POST`  | `/api/clinic/applicants/{app}/medical/approve`    | Issues official medical clearance (`admitted` status)  |

#### College Dean Module (`/api/dean/*` — Role: `college_dean`)

| Method | Endpoint                              | Description                                                 |
| ------ | ------------------------------------- | ----------------------------------------------------------- |
| `GET`  | `/api/dean/applicants`                | Submitted applicants who selected the dean's college        |
| `GET`  | `/api/dean/interviews`                | Interview roster for the dean's college                     |
| `POST` | `/api/dean/applicants/{no}/interview` | Records evaluation grade (Restricted) and interview outcome |
| `GET`  | `/api/dean/exam-questions`            | Question bank for college entrance exam subject             |
| `POST` | `/api/dean/exam-questions`            | Creates new exam question with multiple choices             |

#### Reports Module (`/api/reports/*`)

Exportable in JSON, CSV, XLSX, and PDF (`?format=csv`):

- `applicants-per-program` (1st, 2nd, 3rd choice distribution)
- `status-breakdown` (pipeline drop-off & progression)
- `document-completion` (compliance monitoring)
- `pass-rate` (exam analytics)
- `daily-submissions` (intake velocity)
- `exam-attendance` (proctor reconciliation)
- `admitted-applicants` (Registrar format)
- `possible-duplicates` (fraud & multi-account detection)
- `medical-completion` (clinic clearance analytics)

---

### 3.4. Required Configuration in OAS to Connect with DMS

To link the OAS (`Admission_System`) with the live DMS, the following environment variables must be configured in OAS `.env`:

```env
# Enable DMS Driver for Outbox Synchronization
DMS_DRIVER=http
DMS_BASE_URL=https://dms.plpasig.edu.ph/api/v1
DMS_TOKEN=dms_token_generated_for_oas_connector
DMS_TIMEOUT=30

# Route File Storage Directly to DMS
DOCUMENT_STORE=dms

# Nightly EMS Staff Deactivation Sync
EMS_BASE_URL=https://ems.plpasig.edu.ph/api/v1
EMS_TOKEN=ems_bearer_token_for_oas
EMS_TIMEOUT=20
```

---

---

## 4. TAP-PMS (Payroll & Attendance Management System) Endpoints

### Authentication

- Header: `Authorization: Bearer <PMS_API_TOKEN>`

### Endpoints

#### 4.1. Push Attendance Logs

- **HTTP Method:** `POST`
- **Route:** `/api/attendance/logs`
- **Request Body:**

```json
{
  "device_id": "GATE-01",
  "logs": [
    {
      "employee_no": "23-00142",
      "timestamp": "2026-10-31 07:54:12",
      "type": "in"
    }
  ]
}
```

#### 4.2. Fetch Payslips

- **HTTP Method:** `GET`
- **Route:** `/api/payroll/employees/{employeeNo}/payslips`
- **Response `200 OK`:**

```json
{
  "success": true,
  "data": [
    {
      "period": "2026-10-16 to 2026-10-31",
      "gross_pay": 24500.0,
      "deductions": 2100.0,
      "net_pay": 22400.0,
      "document_reference": "PAYSLIP-202610-2300142"
    }
  ]
}
```

---

## 5. How Connected Systems Push & Integrate with DMS

> [!NOTE]
> **SDD V2.0 Architecture & Scope Clarification:**
> Per **DMS-SDD v2.0 (Section 3.5 & 3.6)**, PMS, EMS, and ES maintain their own private document custody and transactional databases.
>
> - **OAS ↔ DMS ↔ ES:** OAS releases verified applicant requirement documents (`PSA`, `FORM_137`, `VALID_ID`, `PARENT_ID`, `AFFIDAVIT`, `PHOTO`, `EXAM_TICKET`) into DMS custody for authorized retrieval by ES upon enrollment.
> - **EMS / PMS / ES → DMS:** Push idempotent history receipts and audit snapshots (`POST /api/v1/history`) for centralized immutable auditing without duplicating transactional file storage.

### 5.1. Authenticating as a Connector to DMS

Each connected system (EMS, PMS, ES, OAS) is registered as a Connector in DMS (`connectors` table).

- Header: `Authorization: Bearer <CONNECTOR_TOKEN>`
- Scoped strictly to that system's `source` (`EMS`, `PMS`, `ES`, `OAS`), `school_id` (`PLP`), and `campus` (`Main`).

### 5.2. Pushing ERP History Events (`POST /api/v1/history`)

When EMS or PMS modifies employee records or payroll, it syncs state into DMS:

```http
POST /api/v1/history
Authorization: Bearer <CONNECTOR_TOKEN>
Content-Type: application/json
X-Event-ID: evt_20261031_0001
X-Record-Key: ems:employee:23-00142
X-Record-Type: employee
X-Record-ID: 23-00142
X-Revision: 1
X-Operation: upsert

{
  "employee_no": "23-00142",
  "name": "Maria Santos",
  "status": "active",
  "college": "College of Computer Studies"
}
```

- **Guarantees:** Strictly idempotent. If the same `X-Event-ID` or `(X-Record-Key, X-Revision)` is delivered multiple times, DMS returns the original receipt with HTTP 200 without duplicate processing.

### 5.3. Releasing Applicant Document Packets (`POST /api/v1/applicant-packets`)

When OAS releases an admitted student's document packet into DMS:

```http
POST /api/v1/applicant-packets
Authorization: Bearer <OAS_CONNECTOR_TOKEN>
Content-Type: application/json

{
  "applicant_id": "APP-2026-00891",
  "revision": 1,
  "released": true,
  "versions": {
    "form_137": "ver_01h9x3...",
    "birth_certificate": "ver_01h9x4..."
  }
}
```

---

## 6. Environment Configuration Template

Add to `.env` in DMS for cross-system orchestration:

```dotenv
# ERP & Connected Systems API Bases
EMS_API_URL="http://127.0.0.1:8001/api/v1"
EMS_API_TOKEN="ems_sec_prod_live_token_here"

OAS_API_URL="http://127.0.0.1:8002/api"
OAS_API_KEY="oas_integration_key_here"

PMS_API_URL="http://127.0.0.1:8003/api"
PMS_API_TOKEN="pms_sec_prod_live_token_here"
```
