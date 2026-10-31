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

## 3. OAS (Online Admission System) Endpoints

### Authentication

- Integration endpoints:
  - Header: `X-API-Key: <OAS_INTEGRATION_KEY>`
  - IP allowlist checked by `integration` middleware.
- Applicant document endpoints:
  - Header: `Authorization: Bearer <JWT_APPLICANT_TOKEN>`

### Endpoints

#### 3.1. Fetch Admitted Applicants

- **HTTP Method:** `GET`
- **Route:** `/api/integrations/registrar/admitted`
- **Query Parameters:** `academic_year` (string, e.g. `2026-2027`), `semester` (integer, e.g. `1`)
- **Response `200 OK`:**

```json
{
  "success": true,
  "applicants": [
    {
      "applicant_id": "APP-2026-00891",
      "first_name": "Juan",
      "last_name": "Dela Cruz",
      "program_code": "BSIT",
      "admitted_at": "2026-10-25T14:30:00Z",
      "documents": {
        "form_137": "uuid-v1-key",
        "birth_certificate": "uuid-v2-key",
        "good_moral": "uuid-v3-key"
      }
    }
  ]
}
```

#### 3.2. Assign Student Number (Enrollment Handshake)

- **HTTP Method:** `POST`
- **Route:** `/api/integrations/enrollment/student-number`
- **Request Body:**

```json
{
  "applicant_id": "APP-2026-00891",
  "student_number": "2026-00891",
  "program_id": 4
}
```

#### 3.3. Applicant Document Access

- **HTTP Method:** `GET`
- **Route:** `/api/applicant/documents/{key}/file`
- **Route Parameter:** `key` — document type key (e.g. `form_137`, `psa_birth_cert`)
- **Headers:** `Authorization: Bearer <JWT_TOKEN>`
- **Response:** Raw binary file stream with `Content-Type: application/pdf`, `Content-Disposition: attachment; filename="form_137.pdf"`.

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
