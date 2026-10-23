# DMS Backend API Specification (API_SPECS.md)

This document is the authoritative API contract between the DMS Backend and the DMS Frontend Agent. It specifies the authentication, RBAC authorization matrix, database models/schemas, exact HTTP request/response schemas, error structures, and client-side status badges.

---

## 1. Architecture & Authentication

### 1.1 Base URLs
- **Web / Frontend API**: `http://127.0.0.1:8011` (or local port)
- **Base Paths**:
  - Web/Session Endpoints: `/documents`, `/workspaces`, `/files`, `/account`, `/folders`
  - ERP Integration Connector API: `/api/v1`

### 1.2 Authentication & Headers
For all Frontend AJAX / Fetch / Axios requests:
- **Session Authentication**: Browser cookie-based session (authenticated via `/login`).
- **Required Request Headers**:
  ```http
  Accept: application/json
  X-CSRF-TOKEN: <csrf-token-from-meta-tag>
  ```
  *(Include `Content-Type: application/json` for JSON bodies; omit `Content-Type` for `FormData` multipart uploads so the browser sets the boundary automatically).*

---

## 2. Role-Based Access Control (RBAC) Specification

### 2.1 Role Definitions
| Role Code | Display Label | Description |
| :--- | :--- | :--- |
| `super_admin` | **Super Admin** | Institution-wide oversight (`source = null`). Manages all accounts, connectors, and DMS-held documents across all ERP workspaces within school/campus. Full audit visibility. |
| `admin` | **Admin** | Workspace/source-scoped administrator (e.g. assigned to `EMS`, `PMS`, `ES`, or `OAS`). Manages Staff accounts, documents, and folders within their assigned source. |
| `staff` | **Staff** | Working office account. Can upload documents to their assigned source, view source documents, edit/delete owned documents, and access documents explicitly shared with them. Legacy aliases: `employee`, `teacher`, `hr`, `payroll`. |

### 2.2 Data Classification Levels
1. **`Internal`**: General working documents. Readable by all active users in that source + Super Admin. Folder-level grants inherit access to `Internal` documents.
2. **`Confidential`**: Sensitive records (e.g. student transcripts, admissions). Readable only by the document owner, source Admin, Super Admin, and explicit document-level recipients. Folder grants **never** widen Confidential access.
3. **`Restricted`**: Highest sensitivity (e.g. payroll records, executive memos). Readable **only** by the document owner, Super Admin, and explicit document-level recipients. Source Admin has **no** access unless explicitly shared. **Only Super Admin can share or revoke shares on Restricted documents.**

### 2.3 Document Share Permissions
- **`Viewer`**: Can view document metadata and download clean files.
- **`Commenter`**: Can view, download, and post comments (max 2,000 characters).
- **`Editor`**: Can view, download, comment, rename the document, and upload new versions (revision checked). Cannot move, delete, or reshare.

### 2.4 Permissions Matrix
| Action | Staff (Owner) | Staff (Non-Owner) | Admin (Same Source) | Admin (Other Source) | Super Admin |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **View Internal** | Yes | Yes (in source) | Yes (in source) | Only via Share | Yes |
| **View Confidential** | Yes | Only via Share | Yes (in source) | Only via Share | Yes |
| **View Restricted** | Yes | Only via Share | Only via Share | Only via Share | Yes |
| **Upload New Document** | Yes (in source) | Yes (in source) | Yes (in source) | No | Yes |
| **Upload New Version** | Yes | Only if Editor | Yes (Internal/Confidential) | No | Yes |
| **Rename Document** | Yes | Only if Editor | Yes (Internal/Confidential) | No | Yes |
| **Move to Folder** | Yes (owned folders) | No | Yes (in source) | No | Yes |
| **Trash / Delete** | Yes | No | Yes (Internal/Confidential) | No | Yes |
| **Restore from Trash** | Yes | No | Yes (Internal/Confidential) | No | Yes |
| **Share (Internal/Confidential)** | Yes | No | Yes (in source) | No | Yes |
| **Share (Restricted)** | No | No | No | No | **Yes (Exclusive)** |
| **Comment** | Yes | If Commenter/Editor | Yes | If Shared | Yes |

---

## 3. Database Models & Schema Definitions

### 3.1 `User` (`users` table)
```json
{
  "id": 1,
  "name": "Prof. Juan Dela Cruz",
  "email": "juan@plp.edu.ph",
  "role": "staff",
  "source": "EMS",
  "school_id": "PLP",
  "campus": "Main",
  "active": true,
  "created_at": "2026-10-23T06:00:00Z",
  "updated_at": "2026-10-23T06:00:00Z"
}
```

### 3.2 `Document` (`documents` table)
```json
{
  "id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91",
  "owner_id": 1,
  "folder_id": "89b5d3c8-6623-455b-81f1-32e70e2858b9",
  "source": "EMS",
  "school_id": "PLP",
  "campus": "Main",
  "title": "Faculty Service Record 2026",
  "subject": "HR Records",
  "reference": "REF-2026-001",
  "category": "Faculty",
  "classification": "Confidential",
  "revision": 1,
  "trashed_at": null,
  "created_at": "2026-10-23T07:00:00Z",
  "updated_at": "2026-10-23T07:00:00Z"
}
```

### 3.3 `DocumentVersion` (`document_versions` table)
```json
{
  "id": "f5c317b4-1018-4e4f-b29b-0e0fd0398b02",
  "document_id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91",
  "number": 1,
  "filename": "faculty_record_v1.pdf",
  "mime": "application/pdf",
  "size": 154820,
  "checksum": "a591a6d40bf420404a011733cfb7b190d62c65bf0bcda32b57b277d9ad9f146e",
  "storage_key": "documents/f5c317b4-1018-4e4f-b29b-0e0fd0398b02",
  "scan_status": "Clean",
  "scan_attempts": 1,
  "created_at": "2026-10-23T07:00:00Z"
}
```

### 3.4 `Folder` (`folders` table)
```json
{
  "id": "89b5d3c8-6623-455b-81f1-32e70e2858b9",
  "owner_id": 1,
  "parent_id": null,
  "name": "Faculty Documents",
  "source": "EMS",
  "school_id": "PLP",
  "campus": "Main",
  "revision": 1,
  "created_at": "2026-10-23T06:30:00Z",
  "updated_at": "2026-10-23T06:30:00Z"
}
```

### 3.5 `Share` (`shares` table)
```json
{
  "id": 12,
  "document_id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91",
  "recipient_id": 2,
  "version_id": null,
  "permission": "Viewer",
  "expires_at": "2026-11-23T07:00:00Z",
  "revoked_at": null,
  "memo_generated": false,
  "created_at": "2026-10-23T07:30:00Z",
  "updated_at": "2026-10-23T07:30:00Z"
}
```

---

## 4. Endpoints Specification

### 4.1 Get Current User Account Profile
Retrieves the logged-in user, assigned source(s), and RBAC capabilities.

- **Method**: `GET`
- **Path**: `/account`
- **Headers**:
  ```http
  Accept: application/json
  ```
- **Response 200 OK**:
  ```json
  {
    "user": {
      "id": 1,
      "name": "Prof. Juan Dela Cruz",
      "email": "juan@plp.edu.ph",
      "role": "staff",
      "role_label": "Staff",
      "source": "EMS",
      "sources": ["EMS"],
      "school_id": "PLP",
      "campus": "Main",
      "active": true
    },
    "home_path": "/workspaces/ems"
  }
  ```

---

### 4.2 List Documents (Search & Filter)
Primary API to populate document tables and list views. Supports filters, pagination, and search.

- **Method**: `GET`
- **Path**: `/documents`
- **Query Parameters**:
  | Parameter | Type | Required | Default | Description |
  | :--- | :--- | :--- | :--- | :--- |
  | `source` | string | No | User's source | Workspace code: `EMS`, `PMS`, `ES`, `OAS`. |
  | `view` | string | No | `all` | Filter view: `all`, `mine`, `shared`, `starred`, `trash`. |
  | `folder_id` | UUID | No | null | Show only documents in this folder. |
  | `q` | string | No | null | Search text against document title or reference. |
  | `page` | integer| No | 1 | Page number. |
  | `per_page` | integer| No | 15 | Items per page (max 100). |
- **Headers**:
  ```http
  Accept: application/json
  ```
- **Response 200 OK**:
  ```json
  {
    "data": [
      {
        "id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91",
        "owner_id": 1,
        "folder_id": "89b5d3c8-6623-455b-81f1-32e70e2858b9",
        "source": "EMS",
        "school_id": "PLP",
        "campus": "Main",
        "title": "Faculty Service Record 2026",
        "subject": "HR Records",
        "reference": "REF-2026-001",
        "category": "Faculty",
        "classification": "Confidential",
        "revision": 1,
        "trashed_at": null,
        "created_at": "2026-10-23T07:00:00Z",
        "updated_at": "2026-10-23T07:00:00Z",
        "starred": true,
        "latest_version": {
          "id": "f5c317b4-1018-4e4f-b29b-0e0fd0398b02",
          "number": 1,
          "filename": "faculty_record_v1.pdf",
          "mime": "application/pdf",
          "size": 154820,
          "checksum": "a591a6d40bf420404a011733cfb7b190d62c65bf0bcda32b57b277d9ad9f146e",
          "scan_status": "Clean",
          "created_at": "2026-10-23T07:00:00Z"
        },
        "permissions": {
          "can_edit": true,
          "can_manage": true,
          "can_share": true
        }
      }
    ],
    "meta": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 15,
      "total": 1
    }
  }
  ```

---

### 4.3 Workspace Overview & Dashboard Data
Retrieves workspace stats, folders, documents, and audit counters.

- **Method**: `GET`
- **Path**: `/workspaces/{source}/{section?}`
- **Route Parameters**:
  - `source`: e.g. `ems`, `pms`, `es`, `oas`
  - `section`: optional, `overview` (default), `documents`, `history`, `snapshots`
- **Headers**:
  ```http
  Accept: application/json
  ```
- **Response 200 OK**:
  ```json
  {
    "source": "EMS",
    "section": "documents",
    "stats": {
      "documents": 42,
      "blocked": 0,
      "shared": 5,
      "history": 128,
      "folders": 3
    },
    "view": "all",
    "folder": null,
    "parent_folder": null,
    "folders": [
      {
        "id": "89b5d3c8-6623-455b-81f1-32e70e2858b9",
        "name": "Faculty Documents",
        "source": "EMS",
        "document_count": 12
      }
    ],
    "documents": {
      "current_page": 1,
      "data": [ /* document objects */ ],
      "total": 42
    },
    "starred": ["e4b105a2-9907-4e3f-a18a-9d9fc9287a91"]
  }
  ```

---

### 4.4 Upload Document (New Intake)
Uploads a new document file along with its metadata.

- **Method**: `POST`
- **Path**: `/documents`
- **Headers**:
  ```http
  Accept: application/json
  X-CSRF-TOKEN: <csrf-token>
  ```
- **Request Body** (`multipart/form-data`):
  | Field | Type | Required | Description |
  | :--- | :--- | :--- | :--- |
  | `file` | File | **Yes** | File binary, max 10MB (10240 KiB). |
  | `title` | string | **Yes** | Document title (max 200 characters). |
  | `source` | string | **Yes** | Authorized ERP workspace code (e.g. `EMS`). |
  | `classification` | string | **Yes** | One of: `Internal`, `Confidential`, `Restricted`. |
  | `submission_key` | string | **Yes** | Client UUID for idempotency and safe retry. |
  | `folder_id` | UUID | No | Target folder ID. |
  | `subject` | string | No | Optional subject (max 200 chars). |
  | `reference` | string | No | Optional reference number (max 100 chars). |
  | `category` | string | No | Optional category (max 100 chars). |
  | `confirm_duplicate`| boolean| No | Set to `1` / `true` to proceed after duplicate file warning. |

- **Response 201 Created**:
  ```json
  {
    "document_id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91",
    "version_id": "f5c317b4-1018-4e4f-b29b-0e0fd0398b02",
    "revision": 1,
    "scan_status": "Pending"
  }
  ```

- **Duplicate File Advisory Conflict (409 Conflict)**:
  *Triggered when a file with identical SHA-256 exists and user has access to it:*
  ```json
  {
    "code": "duplicate_file",
    "message": "This file matches an existing document you have access to.",
    "duplicates": [
      {
        "document_id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91",
        "title": "Faculty Service Record 2026",
        "version_number": 1,
        "uploaded_at": "2026-10-23 07:00:00",
        "can_add_version": true
      }
    ]
  }
  ```
  *To confirm upload as a separate copy: re-submit the same request with `confirm_duplicate=1`.*

---

### 4.5 Get Document Details
Retrieves complete details of a single document, its versions, activity trail, comments, and shares.

- **Method**: `GET`
- **Path**: `/documents/{document_id}`
- **Headers**:
  ```http
  Accept: application/json
  ```
- **Response 200 OK**:
  ```json
  {
    "document": {
      "id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91",
      "title": "Faculty Service Record 2026",
      "source": "EMS",
      "classification": "Confidential",
      "revision": 1,
      "folder_id": "89b5d3c8-6623-455b-81f1-32e70e2858b9",
      "owner_name": "Prof. Juan Dela Cruz",
      "trashed_at": null,
      "created_at": "2026-10-23T07:00:00Z"
    },
    "versions": [
      {
        "id": "f5c317b4-1018-4e4f-b29b-0e0fd0398b02",
        "number": 1,
        "filename": "faculty_record_v1.pdf",
        "mime": "application/pdf",
        "size": 154820,
        "checksum": "a591a6d40bf420404a011733cfb7b190d62c65bf0bcda32b57b277d9ad9f146e",
        "scan_status": "Clean",
        "created_at": "2026-10-23T07:00:00Z"
      }
    ],
    "permissions": {
      "can_manage": true,
      "can_edit": true,
      "can_comment": true,
      "can_share": true,
      "classifications": ["Internal", "Confidential", "Restricted"]
    },
    "comments": [
      {
        "id": 1,
        "name": "Prof. Juan Dela Cruz",
        "body": "Updated with 2026 semester evaluations.",
        "created_at": "2026-10-23T07:15:00Z"
      }
    ],
    "shares": [
      {
        "id": 12,
        "name": "Maria Santos",
        "email": "maria@plp.edu.ph",
        "permission": "Viewer",
        "expires_at": "2026-11-23T07:00:00Z"
      }
    ]
  }
  ```

---

### 4.6 Upload New Version to Document
Adds an immutable new version to an existing document.

- **Method**: `POST`
- **Path**: `/documents/{document_id}/versions`
- **Headers**:
  ```http
  Accept: application/json
  X-CSRF-TOKEN: <csrf-token>
  ```
- **Request Body** (`multipart/form-data`):
  | Field | Type | Required | Description |
  | :--- | :--- | :--- | :--- |
  | `file` | File | **Yes** | Version file binary, max 10MB. |
  | `revision` | integer | **Yes** | Document's current revision number (optimistic lock). |
  | `submission_key` | string | **Yes** | Unique idempotency UUID. |
  | `confirm_duplicate`| boolean | No | Confirm if identical to a file from another document. |

- **Response 201 Created**:
  ```json
  {
    "document_id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91",
    "version_id": "c71a3299-5561-4fa3-9e4a-b50a41f88764",
    "revision": 2,
    "scan_status": "Pending"
  }
  ```

---

### 4.7 Update Document Metadata / Move / Classify
- **Method**: `PATCH`
- **Path**: `/documents/{document_id}`
- **Headers**:
  ```http
  Accept: application/json
  Content-Type: application/json
  X-CSRF-TOKEN: <csrf-token>
  ```
- **Request Body** (JSON):
  ```json
  {
    "title": "Updated Document Title",
    "folder_id": "89b5d3c8-6623-455b-81f1-32e70e2858b9",
    "revision": 1,
    "classification": "Confidential",
    "confirm_move": false
  }
  ```
- **Response 200 OK**:
  ```json
  {
    "message": "Document updated.",
    "revision": 2,
    "folder_id": "89b5d3c8-6623-455b-81f1-32e70e2858b9",
    "access_gained": 0
  }
  ```
- **Pending Approval (202 Accepted)**:
  *Occurs when a Staff member requests a sensitive classification change:*
  ```json
  {
    "status": "pending",
    "message": "Classification request submitted. Live document unchanged."
  }
  ```

---

### 4.8 Delete / Move to Trash
Moves the document to the trash bin while preserving all immutable versions and files.

- **Method**: `DELETE` or `POST`
- **Path**: `/documents/{document_id}` or `/documents/{document_id}/trash`
- **Headers**:
  ```http
  Accept: application/json
  X-CSRF-TOKEN: <csrf-token>
  ```
- **Request Body**:
  ```json
  {
    "revision": 1,
    "reason": "Outdated record"
  }
  ```
- **Response 200 OK**:
  ```json
  {
    "status": "applied",
    "message": "Document moved to trash. All versions preserved."
  }
  ```

---

### 4.9 Restore from Trash
Restores a trashed document back to active workspace listings.

- **Method**: `POST`
- **Path**: `/documents/{document_id}/restore`
- **Request Body**:
  ```json
  {
    "revision": 2
  }
  ```
- **Response 200 OK**:
  ```json
  {
    "status": "applied",
    "message": "Document restored."
  }
  ```

---

### 4.10 Poll Malware Scan Status
Used by the frontend details page to dynamically update the scan badge without full page reload.

- **Method**: `GET`
- **Path**: `/documents/{document_id}/scan-status`
- **Response 200 OK**:
  ```json
  {
    "versions": [
      {
        "id": "f5c317b4-1018-4e4f-b29b-0e0fd0398b02",
        "scan_status": "Clean"
      }
    ]
  }
  ```

---

### 4.11 Download Clean File
Downloads the raw private version file.

- **Method**: `GET`
- **Path**: `/files/{version_id}`
- **Security Check**:
  - Requires active user session or connector bearer token.
  - User must be authorized under school/campus/source/classification.
  - Version scan status **must be `Clean`**.
  - Verifies live SHA-256 integrity check against stored bytes before streaming.
- **Response 200 OK**: Binary file attachment with `Content-Disposition: attachment; filename="..."`.
- **Response 423 Locked**: If scan status is `Pending`, `Failed`, or `Quarantined`.
  ```json
  {
    "message": "File is blocked until malware scanning succeeds."
  }
  ```

---

### 4.12 In-App File Preview
Streams an in-browser preview for supported formats (PDF, PNG, JPG, WebP, Text, CSV, JSON).

- **Method**: `GET`
- **Path**: `/files/{version_id}/preview`
- **Response 200 OK**: File content served with sandbox security headers (`Content-Security-Policy: default-src 'none'`).
- **Response 415 Unsupported Media Type**: If MIME type has no preview handler; frontend should fallback to download.

---

### 4.13 Toggle Star
Stars or unstars a document for the current user.

- **Method**: `POST`
- **Path**: `/documents/{document_id}/star`
- **Response 200 OK**:
  ```json
  {
    "starred": true,
    "document_id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91"
  }
  ```

---

### 4.14 Post Comment
Adds an activity comment to a document.

- **Method**: `POST`
- **Path**: `/documents/{document_id}/comments`
- **Request Body**:
  ```json
  {
    "body": "Please review section 2 before publication."
  }
  ```
- **Response 201 Created**:
  ```json
  {
    "message": "Comment added."
  }
  ```

---

### 4.15 Share Document
Grants a user `Viewer`, `Commenter`, or `Editor` permission to this document.

- **Method**: `POST`
- **Path**: `/documents/{document_id}/shares`
- **Request Body**:
  ```json
  {
    "recipient_id": 4,
    "permission": "Viewer",
    "expires_at": "2026-11-23T23:59:59Z"
  }
  ```
- **Response 200 OK**:
  ```json
  {
    "status": "applied",
    "message": "Access grant saved."
  }
  ```

---

### 4.16 Revoke Document Share
Revokes an existing document grant.

- **Method**: `DELETE`
- **Path**: `/documents/{document_id}/shares/{share_id}`
- **Response 200 OK**:
  ```json
  {
    "status": "applied",
    "message": "Access revoked."
  }
  ```

---

## 5. Client-Side Status Badges & Indicators

The frontend agent should render consistent badges based on backend response values:

### 5.1 Scan Status (`scan_status`)
| Status Value | Badge Style | Icon / Label | Client Behavior |
| :--- | :--- | :--- | :--- |
| `Pending` | Warning / Yellow pill | ⏳ Scanning queued | Download disabled; poll `/scan-status` every 3s. |
| `Clean` | Success / Green pill |  Clean · Safe | Download & Preview enabled. |
| `Failed` | Danger / Red pill | ⚠ Scan failed | Download blocked; retry scan via Admin. |
| `Quarantined`| Critical / Dark red | 🚫 Blocked threat | Download permanently blocked. |

### 5.2 Classification Status (`classification`)
| Classification | Badge Style | Audience Note |
| :--- | :--- | :--- |
| `Internal` | Neutral / Blue | All active staff in this ERP source. |
| `Confidential` | Warning / Amber | Owner, Source Admin, and named recipients. |
| `Restricted` | Danger / Purple | Owner and named recipients only. Admin excluded. |

---

## 6. Standard Error Response Schemas

All error responses adhere to the standard Laravel JSON error specification:

```json
{
  "message": "Human-readable description of error.",
  "errors": {
    "field_name": [
      "Validation rule failure message."
    ]
  }
}
```

| HTTP Status | Meaning | Typical DMS Scenario |
| :--- | :--- | :--- |
| `401 Unauthorized` | Not authenticated | Session expired or invalid connector token. |
| `403 Forbidden` | Access denied | User's role or scope does not permit this action. |
| `404 Not Found` | Not found | ID does not exist or belongs to another campus/school. |
| `409 Conflict` | Write / Replay conflict | Duplicate file uploaded, concurrent edit, or stale revision. |
| `415 Unsupported` | Format unsupported | Preview requested for an unsupported file format. |
| `422 Unprocessable`| Validation error | Missing required fields, oversize file (>10MB). |
| `423 Locked` | Security lock | Download requested for a file that is not `Clean`. |
| `429 Too Many Req` | Rate limit reached | Exceeded connector (120 req/min) or login throttle. |
