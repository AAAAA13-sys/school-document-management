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

---

## 7. Approvals Queue & Staff Memos API

### 7.1 List Approvals
- **Method**: `GET /approvals`
- **Headers**: `Accept: application/json`
- **Access**: Staff (views own requested changes), Admin (reviews Staff requests in assigned workspace), Super Admin (reviews Admin requests).
- **Response `200 OK`**:
  ```json
  {
    "data": [
      {
        "id": "b96839aa-096b-4e89-9831-27bfb7b3e012",
        "document_id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91",
        "document_title": "Faculty Service Record 2026",
        "document_reference": "REF-2026-001",
        "source": "EMS",
        "action": "document.trash",
        "requester_role": "staff",
        "revision": 2,
        "payload": {
          "reason": "Superseded by new faculty charter"
        },
        "status": "pending",
        "reason": null,
        "decided_at": null,
        "created_at": "2026-10-23T07:15:00Z",
        "requester": {
          "id": 1,
          "name": "Prof. Juan Dela Cruz",
          "email": "juan@plp.edu.ph",
          "role": "staff"
        },
        "reviewer": null
      }
    ],
    "total": 1
  }
  ```

### 7.2 Decide Approval (Approve or Reject)
- **Method**: `POST /approvals/{approval}`
- **Headers**: `Accept: application/json`, `Content-Type: application/json`, `X-CSRF-TOKEN`
- **Request Body**:
  ```json
  {
    "decision": "approve",
    "reason": "Verified and sanctioned by College Dean."
  }
  ```
  *(decision can be `approve` or `reject`; reason is mandatory, max 2,000 characters).*
- **Response `200 OK`**:
  ```json
  {
    "status": "approved",
    "revision": 3
  }
  ```

### 7.3 Publish Staff Memo
- **Method**: `POST /documents/{document}/publish`
- **Headers**: `Accept: application/json`, `Content-Type: application/json`, `X-CSRF-TOKEN`
- **Request Body**:
  ```json
  {
    "revision": 1,
    "version_id": "7fa84128-1b20-4b89-a292-628d0113c2c4",
    "type": "Policy Memo",
    "reference": "MEMO-2026-042",
    "issuer": "Office of the Vice President for Academic Affairs",
    "audience": [1, 2, 5]
  }
  ```
- **Response `200 OK` / `202 Accepted`**:
  ```json
  {
    "status": "applied",
    "revision": 2
  }
  ```
  *(Returns `202` with `{"approval_id": "...", "status": "pending"}` if submitted by Staff requiring Admin approval).*

---

## 8. Account Invitations & Account Changes API

### 8.1 List Account Invitations
- **Method**: `GET /admin/invitations`
- **Headers**: `Accept: application/json`
- **Access**: Admin (views own requested staff invitations), Super Admin (views all invitations institution-wide).
- **Response `200 OK`**: Paginated array of invitations (`id`, `name`, `email`, `role`, `source`, `status`, `expires_at`, `created_at`).

### 8.2 Request New Invitation
- **Method**: `POST /admin/invitations`
- **Headers**: `Accept: application/json`, `Content-Type: application/json`, `X-CSRF-TOKEN`
- **Request Body**:
  ```json
  {
    "name": "Maria Santos",
    "email": "maria.santos@plp.edu.ph",
    "role": "staff",
    "source": "ES"
  }
  ```
- **Response `201 Created`**:
  ```json
  {
    "id": "18f97e20-302a-464a-bc12-f04642930219",
    "status": "delivered",
    "message": "Invitation email sent."
  }
  ```

### 8.3 Review Staff Invitation (Super Admin)
- **Method**: `POST /admin/invitations/{invitation}/review`
- **Request Body**:
  ```json
  {
    "approve": true,
    "reason": "Credentials confirmed by Registrar."
  }
  ```
- **Response `200 OK`**:
  ```json
  {
    "status": "approved",
    "message": "Invitation decision recorded."
  }
  ```

### 8.4 List Account Change Proposals
- **Method**: `GET /admin/account-requests`
- **Headers**: `Accept: application/json`
- **Response `200 OK`**: Paginated list of proposed changes to user accounts pending Super Admin review.

### 8.5 Decide Account Change Proposal
- **Method**: `POST /admin/account-requests/{change}/decide`
- **Request Body**:
  ```json
  {
    "approve": true,
    "reason": "Approved title promotion."
  }
  ```
- **Response `200 OK`**:
  ```json
  {
    "status": "approved",
    "message": "Account decision recorded."
  }
  ```

---

## 9. Workspace Registry Management API

### 9.1 List Workspaces & Memberships
- **Method**: `GET /admin/workspaces`
- **Access**: Super Admin
- **Headers**: `Accept: application/json`
- **Response `200 OK`**:
  ```json
  {
    "workspaces": [
      {
        "id": 1,
        "school_id": "PLP",
        "campus": "Main",
        "code": "EMS",
        "name": "Employee Management System",
        "kind": "erp",
        "status": "active"
      }
    ],
    "users": [...],
    "memberships": {
      "1": [
        {
          "id": 1,
          "workspace_id": 1,
          "user_id": 2
        }
      ]
    }
  }
  ```

### 9.2 Create Workspace
- **Method**: `POST /admin/workspaces`
- **Request Body**:
  ```json
  {
    "code": "RESEARCH",
    "name": "Research & Publication Office",
    "kind": "internal"
  }
  ```
- **Response `201 Created`**:
  ```json
  {
    "message": "Workspace created.",
    "workspace": {
      "code": "RESEARCH",
      "name": "Research & Publication Office",
      "kind": "internal",
      "status": "active"
    }
  }
  ```

### 9.3 Update Workspace
- **Method**: `PATCH /admin/workspaces/{workspace}`
- **Request Body**:
  ```json
  {
    "name": "Updated Office Name",
    "status": "active"
  }
  ```
- **Response `200 OK`**:
  ```json
  {
    "message": "Workspace updated.",
    "workspace": { ... }
  }
  ```

### 9.4 Assign / Revoke Workspace Membership
- **Method**: `POST /admin/workspaces/{workspace}/membership`
- **Request Body**:
  ```json
  {
    "user_id": 5,
    "action": "grant"
  }
  ```
  *(action: `grant` or `revoke`)*
- **Response `200 OK`**:
  ```json
  {
    "message": "Workspace membership updated.",
    "action": "grant",
    "user_id": 5,
    "workspace_id": 1
  }
  ```

---

## 10. Admin Console & Connectors API

### 10.1 Admin Section Summary
- **Method**: `GET /admin/{section}`
- **Sections**: `overview`, `repository`, `accounts`, `connectors`, `audit`, `scans`
- **Headers**: `Accept: application/json`
- **Response `200 OK`**:
  ```json
  {
    "section": "overview",
    "stats": {
      "documents": 142,
      "accounts": 28,
      "history": 1205,
      "connectors": 4,
      "blocked": 0
    },
    "workspace_counts": {
      "EMS": 45,
      "PMS": 30,
      "ES": 52,
      "OAS": 15
    },
    "accounts": [...],
    "connectors": [...]
  }
  ```

### 10.2 Create Connector
- **Method**: `POST /admin/connectors`
- **Request Body**:
  ```json
  {
    "name": "OAS Gateway Production",
    "source": "OAS"
  }
  ```
- **Response `201 Created`**:
  ```json
  {
    "message": "Connector created. Copy its token now; it will not be shown again.",
    "connector_token": "a1b2c3d4e5f6... (64 hex characters)",
    "connector_id": 8
  }
  ```

### 10.3 Revoke Connector
- **Method**: `DELETE /admin/connectors/{connector}`
- **Response `200 OK`**:
  ```json
  {
    "message": "Connector revoked.",
    "status": "revoked"
  }
  ```

---

## 11. Audit Legal Holds Admin API

### 11.1 List Legal Holds
- **Method**: `GET /admin/legal-holds`
- **Access**: Super Admin
- **Headers**: `Accept: application/json`
- **Response `200 OK`**: Paginated array of legal holds (`id`, `school_id`, `campus`, `reason`, `released_at`, `created_at`, `actor`).

### 11.2 Record Legal Hold
- **Method**: `POST /admin/legal-holds`
- **Request Body**:
  ```json
  {
    "reason": "Notice of pending litigation Docket No. 2026-CV-0199; document disposal suspended."
  }
  ```
- **Response `201 Created`**:
  ```json
  {
    "message": "Legal hold recorded. Audit history is retained; disposal suspended.",
    "legal_hold": {
      "id": 1,
      "school_id": "PLP",
      "campus": "Main",
      "actor_id": 1,
      "reason": "Notice of pending litigation Docket No. 2026-CV-0199; document disposal suspended.",
      "released_at": null,
      "created_at": "2026-10-23T07:30:00Z"
    }
  }
  ```

### 11.3 Release Legal Hold
- **Method**: `POST /admin/legal-holds/{hold}/release`
- **Request Body**:
  ```json
  {
    "reason": "Court resolution reached and case dismissed."
  }
  ```
- **Response `200 OK`**:
  ```json
  {
    "message": "Legal hold released.",
    "status": "released"
  }
  ```

---

## 12. OAS-to-ES Applicant Packet Integration API (INT-04 & INT-07)

### 12.1 OAS Receive / Release Applicant Packet
- **Method**: `POST /api/v1/applicant-packets`
- **Auth**: Connector Bearer Token (`source: OAS`)
- **Request Body**:
  ```json
  {
    "event_id": "EVT-OAS-2026-0012",
    "applicant_id": "APP-2026-9012",
    "revision": 1,
    "released": true,
    "admitted": true,
    "verified": true,
    "version_ids": [
      "7fa84128-1b20-4b89-a292-628d0113c2c4"
    ]
  }
  ```
- **Response `201 Created`**:
  ```json
  {
    "packet_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "revision": 1,
    "applied": true
  }
  ```
  *(Security enforcement: Release fails with `403` if any document belongs to a medical or clinical category, and fails with `423` if any version is not `Clean`).*

### 12.2 ES Retrieve Released Applicant Packet
- **Method**: `GET /api/v1/applicant-packets/{packet}`
- **Auth**: Connector Bearer Token (`source: ES`)
- **Response `200 OK`**:
  ```json
  {
    "packet_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "applicant_id": "APP-2026-9012",
    "revision": 1,
    "versions": [
      {
        "document_id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91",
        "version_id": "7fa84128-1b20-4b89-a292-628d0113c2c4",
        "filename": "form137.pdf",
        "checksum": "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
      }
    ]
  }
  ```

### 12.3 ES Download Packet Version File
- **Method**: `GET /api/v1/applicant-packets/{packet}/files/{version}`
- **Auth**: Connector Bearer Token (`source: ES`)
- **Response `200 OK`**: Binary stream of the clean version file.

---

## 13. Central Enterprise Hub & Inter-Service Integration Endpoints

All external university subsystems (Admission/OAS, Enrollment/ES, Cashier/PMS, Payroll/EMS) communicate with the DMS as the central enterprise core for cross-subsystem orchestration, centralized data persistence, and relational document linking.

### 13.1 Admissions Subsystem API (`/api/v1/admissions`)
Manages incoming student applicant records, requirement checklists, and relational requirement document links.

#### 13.1.1 List / Search Student Applicants
- **Method**: `GET /api/v1/admissions`
- **Auth**: Connector Bearer Token (`OAS`, `ADMISSION`, `ES`) or Session
- **Query Parameters**:
  - `status` (optional): `pending`, `under_review`, `admitted`, `rejected`, `waitlisted`
  - `program` (optional): Filter by program code/name
  - `search` (optional): Full-text search on applicant number, first/last name, email
  - `with_documents` (optional, default `true`): Eager-load linked document metadata
  - `page` (default: 1), `per_page` (default: 15, max: 100)
- **Response `200 OK`**:
  ```json
  {
    "status": "success",
    "data": [
      {
        "id": 1,
        "applicant_number": "APP-2026-0001",
        "first_name": "Juan",
        "middle_name": "Protacio",
        "last_name": "Rizal",
        "suffix": null,
        "email": "juan.rizal@plp.edu.ph",
        "phone": "09181234567",
        "program_applied": "BS Information Technology",
        "admission_status": "pending",
        "school_id": "PLP",
        "campus": "Main",
        "applied_at": "2026-10-26T07:00:00Z",
        "requirements": [
          {
            "type": "FORM_137",
            "document_id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91",
            "status": "verified"
          }
        ],
        "applicant_documents": [
          {
            "id": 1,
            "document_type": "FORM_137",
            "verification_status": "verified",
            "document": { ... }
          }
        ]
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

#### 13.1.2 Ingest Student Applicant (Single or Batch)
- **Method**: `POST /api/v1/admissions`
- **Auth**: Connector Bearer Token (`OAS`, `ADMISSION`, `ES`)
- **Single Payload**:
  ```json
  {
    "applicant_number": "APP-2026-0001",
    "first_name": "Juan",
    "middle_name": "Protacio",
    "last_name": "Rizal",
    "suffix": null,
    "email": "juan.rizal@plp.edu.ph",
    "phone": "09181234567",
    "program_applied": "BS Information Technology",
    "admission_status": "pending",
    "requirements": [
      {
        "type": "FORM_137",
        "document_id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91",
        "status": "verified",
        "notes": "Transcript validated against DepEd portal."
      }
    ],
    "metadata": {
      "gpa": 94.8,
      "strand": "STEM"
    }
  }
  ```
- **Batch Payload**:
  ```json
  {
    "batch": [
      { "applicant_number": "APP-2026-0002", "first_name": "Andres", "last_name": "Bonifacio", ... },
      { "applicant_number": "APP-2026-0003", "first_name": "Emilio", "last_name": "Aguinaldo", ... }
    ]
  }
  ```
- **Response `201 Created`** (or `200 OK` on idempotent update):
  ```json
  {
    "status": "success",
    "message": "Student applicant successfully registered.",
    "created": true,
    "data": { ... }
  }
  ```

#### 13.1.3 Get Applicant Details
- **Method**: `GET /api/v1/admissions/{id}` (where `{id}` is applicant primary key or `applicant_number`)
- **Response `200 OK`**: Complete applicant profile with relational document versions and verification notes.

#### 13.1.4 Update Applicant Status & Requirements
- **Method**: `PATCH /api/v1/admissions/{id}`
- **Payload**:
  ```json
  {
    "admission_status": "admitted",
    "requirements": [
      {
        "type": "PSA_BIRTH_CERT",
        "document_id": "99b5d3c8-6623-455b-81f1-32e70e2858b9",
        "status": "verified"
      }
    ]
  }
  ```
- **Response `200 OK`**: Updated applicant details.

---

### 13.2 Enrollment Subsystem API (`/api/v1/enrollment`)
Transitions admitted applicants to student enrollee status, allocating academic subjects and unit quotas while indexing the Certificate of Registration (COR).

#### 13.2.1 List Enrollees
- **Method**: `GET /api/v1/enrollment`
- **Query Parameters**: `program`, `year_level`, `academic_year`, `semester`, `status`, `search`, pagination.
- **Response `200 OK`**: Paginated list of student enrollees with linked COR documents.

#### 13.2.2 Transition & Enroll Student
- **Method**: `POST /api/v1/enrollment`
- **Auth**: Connector Bearer Token (`ES`, `ENROLLMENT`, `OAS`)
- **Payload**:
  ```json
  {
    "student_number": "2026-00101-MN-0",
    "applicant_id": 1,
    "program": "BS Information Technology",
    "year_level": 1,
    "academic_year": "2026-2027",
    "semester": "1st Semester",
    "enrolled_units": 21,
    "course_allocations": [
      {
        "course_code": "IT101",
        "description": "Introduction to Information Technology",
        "units": 3,
        "section": "IT-1A",
        "schedule": "MWF 08:00-09:00"
      },
      {
        "course_code": "IT102",
        "description": "Computer Programming 1",
        "units": 3,
        "section": "IT-1A",
        "schedule": "TTH 10:00-11:30"
      }
    ],
    "enrollment_status": "enrolled",
    "clearance_status": "cleared",
    "cor_document_id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91"
  }
  ```
- **Governance Automation**: Linking `applicant_id` automatically transitions the applicant's `admission_status` to `admitted`.
- **Response `201 Created`**:
  ```json
  {
    "status": "success",
    "message": "Student enrollment registered successfully.",
    "created": true,
    "data": { ... }
  }
  ```

#### 13.2.3 Update Enrollee Allocation & Status
- **Method**: `PATCH /api/v1/enrollment/{id}`
- **Payload**: `{ "enrolled_units": 24, "clearance_status": "cleared" }`
- **Response `200 OK`**: Updated enrollment record.

---

### 13.3 Cashier Subsystem API (`/api/v1/cashier`)
Tracks student fee assessments, balances, and validates official receipts.

#### 13.3.1 List Assessments
- **Method**: `GET /api/v1/cashier`
- **Query Parameters**: `status`, `student_number`, `account_type`, `academic_year`, `semester`, `search`, pagination.
- **Response `200 OK`**: Paginated array of assessments with payment status.

#### 13.3.2 Create Fee Assessment
- **Method**: `POST /api/v1/cashier`
- **Auth**: Connector Bearer Token (`CASHIER`, `CAS`, `PMS`, `ES`)
- **Payload**:
  ```json
  {
    "assessment_number": "ASSESS-2026-001",
    "student_number": "2026-00101-MN-0",
    "student_name": "Juan Rizal",
    "account_type": "tuition",
    "academic_year": "2026-2027",
    "semester": "1st Semester",
    "total_amount": 15000.00,
    "amount_paid": 5000.00,
    "payment_method": "cash"
  }
  ```
- **Response `201 Created`**:
  ```json
  {
    "status": "success",
    "message": "Fee assessment created successfully.",
    "data": {
      "assessment_number": "ASSESS-2026-001",
      "total_amount": 15000.00,
      "amount_paid": 5000.00,
      "balance": 10000.00,
      "payment_status": "assessed"
    }
  }
  ```

#### 13.3.3 Verify Payment & Index Official Receipt
- **Method**: `POST /api/v1/cashier/{id}/verify`
- **Payload**:
  ```json
  {
    "or_number": "OR-2026-99123",
    "amount_paid": 10000.00,
    "payment_method": "online_transfer",
    "receipt_document_id": "f5c317b4-1018-4e4f-b29b-0e0fd0398b02",
    "verified_by": "Teller Jane Doe"
  }
  ```
- **Response `200 OK`**:
  ```json
  {
    "status": "success",
    "message": "Payment successfully verified.",
    "data": {
      "assessment_number": "ASSESS-2026-001",
      "or_number": "OR-2026-99123",
      "amount_paid": 15000.00,
      "balance": 0.00,
      "payment_status": "verified",
      "verified_at": "2026-10-26T07:15:00Z"
    }
  }
  ```

---

### 13.4 Payroll Subsystem API (`/api/v1/payroll`)
Manages university faculty/staff payroll records and centralized digital payslip document indexing.

#### 13.4.1 List Payroll Records
- **Method**: `GET /api/v1/payroll`
- **Query Parameters**: `period`, `department`, `employee_id`, `status`, `employment_type`, `search`, pagination.
- **Response `200 OK`**: Paginated payroll records.

#### 13.4.2 Ingest Payroll Record
- **Method**: `POST /api/v1/payroll`
- **Auth**: Connector Bearer Token (`PMS`, `PAYROLL`, `EMS`)
- **Payload**:
  ```json
  {
    "payroll_period": "2026-10-A",
    "employee_id": "EMP-10023",
    "employee_name": "Prof. Maria Santos",
    "department": "College of Computer Studies",
    "employment_type": "faculty",
    "gross_pay": 50000.00,
    "deductions": 6000.00,
    "net_pay": 44000.00,
    "deduction_breakdown": {
      "withholding_tax": 3000.00,
      "gsis": 2000.00,
      "philhealth": 600.00,
      "pagibig": 400.00
    },
    "disbursement_status": "processed",
    "payslip_document_id": "f5c317b4-1018-4e4f-b29b-0e0fd0398b02"
  }
  ```
- **Response `201 Created`**:
  ```json
  {
    "status": "success",
    "message": "Payroll record indexed successfully.",
    "created": true,
    "data": { ... }
  }
  ```

#### 13.4.3 Update Disbursement Status
- **Method**: `PATCH /api/v1/payroll/{id}`
- **Payload**: `{ "disbursement_status": "disbursed" }`
- **Response `200 OK`**: Record updated with `disbursed_at` timestamp.

---

### 13.5 Universal Documents Repository API (`/api/v1/documents`)
Universal file ingestion, metadata tagging, status tracking, and centralized retrieval.

#### 13.5.1 Universal Multipart Upload with Metadata Tagging
- **Method**: `POST /api/v1/documents`
- **Headers**:
  ```http
  Authorization: Bearer <connector-token>
  Idempotency-Key: <unique-uuid>
  Content-Type: multipart/form-data
  ```
- **Form Fields**:
  - `file`: Binary file (max 10MB)
  - `title`: string (max 200)
  - `classification`: `Internal`, `Confidential`, `Restricted`
  - `category`: optional string (e.g. `Official`, `Employment`, `Admissions`, `Receipt`)
  - `reference`: optional string (e.g. `REF-2026-001`)
  - `tags`: optional JSON array or comma-separated string
  - `metadata`: optional JSON object for extensible attributes
- **Response `201 Created`**:
  ```json
  {
    "document_id": "e4b105a2-9907-4e3f-a18a-9d9fc9287a91",
    "version_id": "f5c317b4-1018-4e4f-b29b-0e0fd0398b02",
    "revision": 1,
    "scan_status": "Pending"
  }
  ```

#### 13.5.2 Document Metadata Tracking & Centralized Retrieval
- **Method**: `GET /api/v1/documents/{document}`
- **Response `200 OK`**: Returns document metadata, parsed `tags`, `metadata`, and list of all versions with their malware scan statuses (`Clean`, `Pending`, `Infected`) and checksums.

#### 13.5.3 Update Document Metadata & Tags
- **Method**: `PATCH /api/v1/documents/{document}`
- **Payload**:
  ```json
  {
    "reference": "HR-2026-CONT-REVISED",
    "tags": ["faculty", "contract", "2026", "signed"],
    "metadata": {
      "approved_by": "Dean of CCS"
    }
  }
  ```
- **Response `200 OK`**: Updated document object.

---

### 13.6 Business Governance & Access Control Matrix

| Endpoint | Authorized Connector Sources | Primary Operation | Cross-Subsystem Governance Rules |
| :--- | :--- | :--- | :--- |
| `/api/v1/admissions` | `OAS`, `ADMISSION`, `ES` | Intake & Requirements | Requires valid applicant number; duplicate submissions update idempotently; requirement documents must belong to same school/campus tenant. |
| `/api/v1/enrollment` | `ES`, `ENROLLMENT`, `OAS` | Enrollee Transition | Linking applicant automatically updates admission status to `admitted`; Certificate of Registration must exist in tenant. |
| `/api/v1/cashier` | `CASHIER`, `CAS`, `PMS`, `ES` | Fee Assessments & Payments | Automatically calculates balance (`total - paid`); verification transitions status to `verified` and requires official receipt #. |
| `/api/v1/payroll` | `PMS`, `PAYROLL`, `EMS` | Payroll & Payslips | Validates net pay against gross and deductions; indexes payslip document to immutable file storage. |
| `/api/v1/documents` | All Active Connectors | Universal File Storage | Enforces 10MB limit, SHA-256 validation, background ClamAV scanning, and multi-tenant isolation. |


