# Connecting ERPs to DMS — Simple Step-by-Step Guide

This guide explains how to connect external school systems (**OAS**, **ES**, **EMS**, **PMS**) to the **Document Management System (DMS)** in plain English with zero jargon.

---

## 1. How the Connection Works (The 1-Minute Summary)

Think of DMS like a **secure digital bank vault**:
- Each external school system gets its own **Key Card (API Token)**.
- When an ERP wants to store or read files, it presents its Key Card to DMS.
- No direct database wiring or complicated network setup is required—just simple web requests using that Key Card.

```mermaid
flowchart LR
    OAS["OAS (Admissions)"] -->|"Bearer Token (Key Card)"| DMS[("DMS Vault")]
    ES["ES (Enrollment)"] -->|"Bearer Token (Key Card)"| DMS
    EMS["EMS (Employees)"] -->|"Bearer Token (Key Card)"| DMS
    PMS["PMS (Payroll)"] -->|"Bearer Token (Key Card)"| DMS
```

---

## 2. Step 1: Create a Connection Key

You can create a connection key in two ways: through the **Website Admin Panel** or via the **Command Line**.

### Option A: Using the Website Panel (Easiest)

1. Open your browser and go to: `http://127.0.0.1:8000/login`
2. Log in with the **Super Admin** account:
   - **Email:** `dms.admin@plpasig.edu.ph`
   - **Password:** *(Your configured DMS admin password)*
3. Click **Admin** in the top navigation bar, then click **ERP connectors** (or go straight to `http://127.0.0.1:8000/admin/connectors`).
4. In the **"Connect a system"** box on the right:
   - **Source:** Pick the system you want to connect (`OAS`, `ES`, `EMS`, or `PMS`).
   - **Connector name:** Type any friendly label (e.g., `PLP Enrollment System`).
   - Click the blue **"Create connection"** button.
5. **Copy the Token:** A green banner will show your new token (a long string of 64 characters).
   > **Note:** Copy this token immediately! For security, DMS only shows it once.

---

### Option B: Using the Command Line (Terminal)

If you prefer using the terminal, run this single command:

```powershell
# Replace ES with OAS, EMS, or PMS depending on the system you need
php artisan dms:connector ES
```

Or generate a token with a custom name/secret:
```powershell
php artisan dms:connector ES --token="my-shared-secret-token-2026"
```

The terminal will print the token and sample headers.

---

## 3. Step 2: Where to Paste the Token

There are two sides to every connection:

### A. The Other ERP System Connecting to DMS (Ingress)
Give the token you copied in Step 1 to the other ERP team. In their project's `.env` configuration file, they paste:

```dotenv
# In the other ERP's .env file (e.g., in the Enrollment System)
DMS_BASE_URL="http://127.0.0.1:8000/api/v1"
DMS_API_TOKEN="<paste_the_token_here>"
```

Whenever that ERP sends an API request to DMS, it adds this header:
```http
Authorization: Bearer <paste_the_token_here>
```

---

### B. DMS Connecting Out to the Other ERP (Egress)
If DMS needs to proactively push data to an external ERP (such as pushing admitted students into ES), open **DMS's own `.env` file** and configure their endpoints:

```dotenv
# In DMS's own .env file

# Link to ES (Enrollment Management System)
ES_API_URL="http://127.0.0.1:8001/api/v1"
ES_API_TOKEN="token_given_by_es_team"

# Link to OAS (Online Admission System)
OAS_API_URL="http://127.0.0.1:8002/api/v1"
OAS_API_TOKEN="token_given_by_oas_team"
```

---

## 4. Real-World Walkthrough: The OAS-to-ES Admission Flow

Here is what happens automatically behind the scenes when a student is admitted:

```mermaid
sequenceDiagram
    autonumber
    participant OAS as 1. Admission (OAS)
    participant DMS as 2. DMS Vault
    participant ES as 3. Enrollment (ES)

    OAS->>DMS: POST /api/v1/applicant-packets<br/>(Locks applicant's PSA, Form 137, ID)
    DMS-->>OAS: 201 Created: Returns packet_id
    OAS->>ES: Sends notification with packet_id
    ES->>DMS: GET /api/v1/applicant-packets/{packet_id}
    DMS-->>ES: 200 OK: Returns clean document list & download links
    ES->>DMS: GET /api/v1/applicant-packets/{packet_id}/files/{version_id}
    DMS-->>ES: Streams clean PDF files directly to registrar
```

1. **Student is Admitted in OAS:** OAS groups the student's birth certificate, Form 137, and IDs into an "Applicant Packet" and sends it to DMS.
2. **DMS Locks & Scans the Files:** DMS verifies all files are scanned for malware (ClamAV) and assigns a unique `packet_id`.
3. **ES Reads the Files Immediately:** ES uses its token to view and download the applicant's official files directly from DMS.
4. **Zero Re-upload:** The student does **not** have to submit their files a second time during enrollment.

---

## 5. Built-in Development Tokens (Ready to Use)

For local development and testing, DMS comes with pre-seeded tokens right out of the box (created by `php artisan db:seed`):

| ERP System | System Name | Pre-Seeded Development Token |
| :--- | :--- | :--- |
| **OAS** | Online Admission System | `dms_dev_oas_token_2026` |
| **ES** | Enrollment Management System | `dms_dev_es_token_2026` |
| **EMS** | Employee Management System | `dms_dev_ems_token_2026` |
| **PMS** | Payroll Management System | `dms_dev_pms_token_2026` |

You can use these tokens immediately with Postman or curl!

---

## 6. Quick Verification Test (Copy & Paste)

To confirm that the connection is working from your terminal:

```powershell
# Test reading history with the ES dev token:
curl.exe -i "http://127.0.0.1:8000/api/v1/history" `
  -H "Authorization: Bearer dms_dev_es_token_2026"
```

If you receive **HTTP 200 OK**, your connection is 100% active and authenticated!
