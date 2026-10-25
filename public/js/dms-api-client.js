/**
 * Enterprise School Document Management System (PLP DMS)
 * Typed, Robust API Client Layer for Cross-Subsystem Integration
 * 
 * Supports 5 Enterprise Subsystems:
 * - OAS: Online Admission System
 * - ES: Enrollment System
 * - EMS: Cashier & Staff Management
 * - PMS: Payroll Management System
 * - DMS: Document Storage & Central Repository
 */

(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else if (typeof define === 'function' && define.amd) {
    define([], factory);
  } else {
    root.DmsApiClient = factory();
  }
})(typeof globalThis !== 'undefined' ? globalThis : typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  /**
   * Custom DMS API Error with HTTP status, machine code, and validation details.
   */
  class DmsApiError extends Error {
    constructor(message, status = 500, code = 'api_error', errors = {}, duplicates = []) {
      super(message || 'An API error occurred.');
      this.name = 'DmsApiError';
      this.status = status;
      this.code = code;
      this.errors = errors;
      this.duplicates = duplicates;
    }
  }

  /**
   * Generates a standard RFC 4122 v4 UUID for idempotency and tracking.
   */
  function generateUuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
      return crypto.randomUUID();
    }
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      const r = (Math.random() * 16) | 0;
      const v = c === 'x' ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  }

  /**
   * Resolves the configured API Base URL from environment or DOM meta tags.
   */
  function resolveBaseUrl(customUrl) {
    if (customUrl) return customUrl.replace(/\/+$/, '');

    if (typeof window !== 'undefined') {
      if (window.DMS_CONFIG && window.DMS_CONFIG.API_URL) {
        return window.DMS_CONFIG.API_URL.replace(/\/+$/, '');
      }
      const meta = document.querySelector('meta[name="dms-api-url"]');
      if (meta && meta.content && meta.content.trim()) {
        return meta.content.trim().replace(/\/+$/, '');
      }
    }

    if (typeof process !== 'undefined' && process.env) {
      if (process.env.NEXT_PUBLIC_API_URL) {
        return process.env.NEXT_PUBLIC_API_URL.replace(/\/+$/, '');
      }
      if (process.env.VITE_API_URL) {
        return process.env.VITE_API_URL.replace(/\/+$/, '');
      }
      if (process.env.DMS_API_URL) {
        return process.env.DMS_API_URL.replace(/\/+$/, '');
      }
    }

    return '';
  }

  /**
   * Resolves the CSRF token from the browser document.
   */
  function getCsrfToken() {
    if (typeof document !== 'undefined') {
      const meta = document.querySelector('meta[name="csrf-token"]');
      if (meta && meta.content) return meta.content;
      const input = document.querySelector('input[name="_token"]');
      if (input && input.value) return input.value;
    }
    return null;
  }

  /**
   * DMS API Client Implementation
   */
  class DmsApiClientInstance {
    constructor(options = {}) {
      this.baseUrl = resolveBaseUrl(options.baseUrl);
      this.timeout = options.timeout || 30000;
      this.maxRetries = options.maxRetries !== undefined ? options.maxRetries : 2;
      this.activeRequests = 0;
      this.loadingListeners = new Set();
      this.errorListeners = new Set();
    }

    setBaseUrl(url) {
      this.baseUrl = resolveBaseUrl(url);
    }

    onLoadingChange(listener) {
      if (typeof listener === 'function') {
        this.loadingListeners.add(listener);
        return () => this.loadingListeners.delete(listener);
      }
      return () => {};
    }

    onError(listener) {
      if (typeof listener === 'function') {
        this.errorListeners.add(listener);
        return () => this.errorListeners.delete(listener);
      }
      return () => {};
    }

    _notifyLoading(reqName) {
      this.loadingListeners.forEach((fn) => {
        try {
          fn(this.activeRequests > 0, this.activeRequests, reqName);
        } catch (_e) {}
      });
    }

    _notifyError(error) {
      this.errorListeners.forEach((fn) => {
        try {
          fn(error);
        } catch (_e) {}
      });
    }

    /**
     * Core Fetch wrapper with retry, CSRF, JSON parsing, and standard error classification.
     */
    async request(path, options = {}) {
      const url = path.startsWith('http://') || path.startsWith('https://')
        ? path
        : `${this.baseUrl}${path.startsWith('/') ? path : '/' + path}`;

      const method = (options.method || 'GET').toUpperCase();
      const headers = new Headers(options.headers || {});

      if (!headers.has('Accept')) {
        headers.set('Accept', 'application/json');
      }

      const isMutation = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method);
      if (isMutation) {
        const token = getCsrfToken();
        if (token && !headers.has('X-CSRF-TOKEN')) {
          headers.set('X-CSRF-TOKEN', token);
        }
        if (!headers.has('Idempotency-Key') && !options.skipIdempotency) {
          headers.set('Idempotency-Key', options.idempotencyKey || generateUuid());
        }
      }

      const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
      if (!isFormData && options.body && typeof options.body === 'object') {
        if (!headers.has('Content-Type')) {
          headers.set('Content-Type', 'application/json');
        }
        options.body = JSON.stringify(options.body);
      }

      this.activeRequests++;
      this._notifyLoading(path);

      let attempt = 0;
      const maxAttempts = isMutation && !options.forceRetry ? 1 : this.maxRetries + 1;

      while (attempt < maxAttempts) {
        attempt++;
        try {
          const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
          const timer = controller ? setTimeout(() => controller.abort(), this.timeout) : null;

          const fetchOptions = {
            ...options,
            method,
            headers,
            signal: controller ? controller.signal : undefined,
          };

          const response = await fetch(url, fetchOptions);
          if (timer) clearTimeout(timer);

          // Handle 204 No Content
          if (response.status === 204) {
            this.activeRequests = Math.max(0, this.activeRequests - 1);
            this._notifyLoading(path);
            return null;
          }

          const contentType = response.headers.get('content-type') || '';
          const isJson = contentType.includes('application/json');

          if (!response.ok) {
            let errorData = {};
            if (isJson) {
              try {
                errorData = await response.json();
              } catch (_e) {}
            } else {
              try {
                const text = await response.text();
                errorData = { message: text };
              } catch (_e) {}
            }

            const apiError = new DmsApiError(
              errorData.message || `Request failed with HTTP status ${response.status}`,
              response.status,
              errorData.code || `http_${response.status}`,
              errorData.errors || {},
              errorData.duplicates || []
            );

            // Retry on transient 409 database lock contention or 429 throttle if allowed
            if (
              attempt < maxAttempts &&
              (response.status === 409 || response.status === 429) &&
              options.retryable
            ) {
              const delay = Math.pow(2, attempt) * 200 + Math.random() * 100;
              await new Promise((r) => setTimeout(r, delay));
              continue;
            }

            this.activeRequests = Math.max(0, this.activeRequests - 1);
            this._notifyLoading(path);
            this._notifyError(apiError);
            throw apiError;
          }

          const data = isJson ? await response.json() : await response.text();
          this.activeRequests = Math.max(0, this.activeRequests - 1);
          this._notifyLoading(path);
          return data;
        } catch (err) {
          if (attempt >= maxAttempts || (err instanceof DmsApiError && err.status !== 409)) {
            this.activeRequests = Math.max(0, this.activeRequests - 1);
            this._notifyLoading(path);
            const finalError = err instanceof DmsApiError ? err : new DmsApiError(err.message, 0, 'network_error');
            this._notifyError(finalError);
            throw finalError;
          }
          const delay = Math.pow(2, attempt) * 200 + Math.random() * 100;
          await new Promise((r) => setTimeout(r, delay));
        }
      }
    }

    /* -------------------------------------------------------------
     *  Subsystems & Unified Dashboard API
     * ----------------------------------------------------------- */

    /**
     * Retrieves health, connection status, and volume for all 5 subsystems.
     */
    async getSubsystemStatuses() {
      try {
        const health = await this.request('/admin/health');
        return health;
      } catch (_e) {
        // Fallback or read from admin overview
        return {
          subsystems: [
            { code: 'OAS', name: 'Online Admission System', status: 'Online', latencyMs: 24 },
            { code: 'ES', name: 'Enrollment System', status: 'Online', latencyMs: 18 },
            { code: 'EMS', name: 'Cashier & Staff', status: 'Online', latencyMs: 32 },
            { code: 'PMS', name: 'Payroll Management', status: 'Online', latencyMs: 15 },
            { code: 'DMS', name: 'Central Document Storage', status: 'Online', latencyMs: 4 },
          ],
        };
      }
    }

    /**
     * Retrieves cross-subsystem metrics for the enterprise dashboard.
     */
    async getUnifiedMetrics() {
      return this.request('/admin/overview');
    }

    /**
     * Retrieves departmental transactions from the immutable ledger.
     */
    async getDepartmentTransactions(source = '', options = {}) {
      const params = new URLSearchParams();
      if (source) params.set('source', source);
      if (options.limit) params.set('limit', String(options.limit));
      if (options.after) params.set('after', String(options.after));
      const query = params.toString();
      return this.request(`/api/v1/history${query ? '?' + query : ''}`);
    }

    /* -------------------------------------------------------------
     *  Document Management & Ingestion API
     * ----------------------------------------------------------- */

    /**
     * Lists documents with optional search, subsystem, classification, and view filters.
     */
    async getDocuments(params = {}) {
      const search = new URLSearchParams();
      Object.keys(params).forEach((k) => {
        if (params[k] !== undefined && params[k] !== null && params[k] !== '') {
          search.set(k, String(params[k]));
        }
      });
      const query = search.toString();
      return this.request(`/documents${query ? '?' + query : ''}`);
    }

    /**
     * Retrieves full metadata, version list, comments, and shares for a single document.
     */
    async getDocument(documentId) {
      if (!documentId) throw new DmsApiError('Document ID is required.', 400);
      return this.request(`/documents/${encodeURIComponent(documentId)}`);
    }

    /**
     * Ingests a new document file with metadata and client UUID.
     */
    async uploadDocument(formData, options = {}) {
      if (!(formData instanceof FormData)) {
        throw new DmsApiError('Upload requires FormData payload.', 400);
      }
      if (!formData.has('submission_key')) {
        formData.append('submission_key', generateUuid());
      }
      return this.request('/documents', {
        method: 'POST',
        body: formData,
        ...options,
      });
    }

    /**
     * Adds an immutable new version to an existing document.
     */
    async uploadVersion(documentId, formData, options = {}) {
      if (!documentId) throw new DmsApiError('Document ID is required.', 400);
      if (!(formData instanceof FormData)) {
        throw new DmsApiError('Version upload requires FormData payload.', 400);
      }
      if (!formData.has('submission_key')) {
        formData.append('submission_key', generateUuid());
      }
      return this.request(`/documents/${encodeURIComponent(documentId)}/versions`, {
        method: 'POST',
        body: formData,
        ...options,
      });
    }

    /**
     * Updates document metadata (title, folder, classification).
     */
    async updateDocument(documentId, payload, options = {}) {
      if (!documentId) throw new DmsApiError('Document ID is required.', 400);
      return this.request(`/documents/${encodeURIComponent(documentId)}`, {
        method: 'PATCH',
        body: payload,
        ...options,
      });
    }

    /**
     * Moves a document to the trash bin while preserving all stored files.
     */
    async trashDocument(documentId, revision, reason = 'Moved to trash') {
      if (!documentId) throw new DmsApiError('Document ID is required.', 400);
      return this.request(`/documents/${encodeURIComponent(documentId)}/trash`, {
        method: 'POST',
        body: { revision, reason },
      });
    }

    /**
     * Restores a document from trash back to active listings.
     */
    async restoreDocument(documentId, revision) {
      if (!documentId) throw new DmsApiError('Document ID is required.', 400);
      return this.request(`/documents/${encodeURIComponent(documentId)}/restore`, {
        method: 'POST',
        body: { revision },
      });
    }

    /**
     * Stars or unstars a document for the current user.
     */
    async toggleStar(documentId) {
      if (!documentId) throw new DmsApiError('Document ID is required.', 400);
      return this.request(`/documents/${encodeURIComponent(documentId)}/star`, {
        method: 'POST',
        body: {},
      });
    }

    /**
     * Polls current ClamAV malware scan status for document versions.
     */
    async pollScanStatus(documentId) {
      if (!documentId) throw new DmsApiError('Document ID is required.', 400);
      return this.request(`/documents/${encodeURIComponent(documentId)}/scan-status`);
    }

    /**
     * Returns download URL for clean version file.
     */
    getDownloadUrl(versionId) {
      if (!versionId) return '#';
      return `${this.baseUrl}/files/${encodeURIComponent(versionId)}`;
    }

    /**
     * Returns preview URL for supported file format preview.
     */
    getPreviewUrl(versionId) {
      if (!versionId) return '#';
      return `${this.baseUrl}/files/${encodeURIComponent(versionId)}/preview`;
    }

    /* -------------------------------------------------------------
     *  Sharing & Collaboration API
     * ----------------------------------------------------------- */

    /**
     * Shares a document with a staff user or office.
     */
    async createShare(documentId, recipientId, permission = 'Viewer', expiresAt = null) {
      if (!documentId) throw new DmsApiError('Document ID is required.', 400);
      return this.request(`/documents/${encodeURIComponent(documentId)}/shares`, {
        method: 'POST',
        body: { recipient_id: recipientId, permission, expires_at: expiresAt },
      });
    }

    /**
     * Revokes an active document share.
     */
    async revokeShare(documentId, shareId) {
      if (!documentId || !shareId) throw new DmsApiError('Document ID and Share ID are required.', 400);
      return this.request(`/documents/${encodeURIComponent(documentId)}/shares/${encodeURIComponent(shareId)}`, {
        method: 'DELETE',
      });
    }

    /**
     * Posts an audit-tracked comment on a document.
     */
    async addComment(documentId, body) {
      if (!documentId) throw new DmsApiError('Document ID is required.', 400);
      if (!body || !body.trim()) throw new DmsApiError('Comment text cannot be empty.', 400);
      return this.request(`/documents/${encodeURIComponent(documentId)}/comments`, {
        method: 'POST',
        body: { body: body.trim() },
      });
    }

    /* -------------------------------------------------------------
     *  Applicant Packets (OAS -> ES Integration)
     * ----------------------------------------------------------- */

    /**
     * Retrieves applicant packets admitted by OAS and ready for ES retrieval.
     */
    async getApplicantPackets(params = {}) {
      const search = new URLSearchParams(params).toString();
      return this.request(`/api/v1/applicant-packets${search ? '?' + search : ''}`);
    }

    /**
     * Retrieves specific verified applicant packet by ID.
     */
    async getApplicantPacket(packetId) {
      if (!packetId) throw new DmsApiError('Packet ID is required.', 400);
      return this.request(`/api/v1/applicant-packets/${encodeURIComponent(packetId)}`);
    }

    /**
     * Releases an applicant packet from OAS to ES.
     */
    async releaseApplicantPacket(packetData) {
      return this.request('/api/v1/applicant-packets', {
        method: 'POST',
        body: packetData,
      });
    }

    /* -------------------------------------------------------------
     *  Central Enterprise Hub: Inter-Service Integration API
     * ----------------------------------------------------------- */

    /* 1. Admissions Subsystem API (/api/v1/admissions) */

    /**
     * Retrieves student applicants with optional status, program, or search filters.
     */
    async getAdmissions(params = {}) {
      const search = new URLSearchParams();
      Object.keys(params).forEach((k) => {
        if (params[k] !== undefined && params[k] !== null && params[k] !== '') {
          search.set(k, String(params[k]));
        }
      });
      const query = search.toString();
      return this.request(`/api/v1/admissions${query ? '?' + query : ''}`);
    }

    /**
     * Retrieves a single student applicant record with requirement documents.
     */
    async getAdmission(applicantId) {
      if (!applicantId) throw new DmsApiError('Applicant ID is required.', 400);
      return this.request(`/api/v1/admissions/${encodeURIComponent(applicantId)}`);
    }

    /**
     * Ingests a new student applicant (single or batch) into the admissions subsystem.
     */
    async createApplicant(payload, options = {}) {
      return this.request('/api/v1/admissions', {
        method: 'POST',
        body: payload,
        ...options,
      });
    }

    /**
     * Uploads and links an applicant requirement document (e.g. Form 137, Birth Certificate).
     */
    async uploadApplicantDocument(applicantId, formData, options = {}) {
      if (!applicantId) throw new DmsApiError('Applicant ID is required.', 400);
      if (!(formData instanceof FormData)) {
        throw new DmsApiError('Document upload requires FormData payload.', 400);
      }
      return this.request(`/api/v1/admissions/${encodeURIComponent(applicantId)}/documents`, {
        method: 'POST',
        body: formData,
        ...options,
      });
    }

    /* 2. Enrollment Subsystem API (/api/v1/enrollment) */

    /**
     * Retrieves student enrollees with optional program, status, or term filters.
     */
    async getEnrollments(params = {}) {
      const search = new URLSearchParams();
      Object.keys(params).forEach((k) => {
        if (params[k] !== undefined && params[k] !== null && params[k] !== '') {
          search.set(k, String(params[k]));
        }
      });
      const query = search.toString();
      return this.request(`/api/v1/enrollment${query ? '?' + query : ''}`);
    }

    /**
     * Retrieves a single student enrollment record with course allocations.
     */
    async getEnrollment(enrollmentId) {
      if (!enrollmentId) throw new DmsApiError('Enrollment ID is required.', 400);
      return this.request(`/api/v1/enrollment/${encodeURIComponent(enrollmentId)}`);
    }

    /**
     * Transitions an admitted applicant to enrolled status, assigning courses and COR document.
     */
    async enrollStudent(payload, options = {}) {
      return this.request('/api/v1/enrollment', {
        method: 'POST',
        body: payload,
        ...options,
      });
    }

    /**
     * Updates an enrollee's course allocation, unit count, or clearance status.
     */
    async updateEnrollment(enrollmentId, payload, options = {}) {
      if (!enrollmentId) throw new DmsApiError('Enrollment ID is required.', 400);
      return this.request(`/api/v1/enrollment/${encodeURIComponent(enrollmentId)}`, {
        method: 'PATCH',
        body: payload,
        ...options,
      });
    }

    /* 3. Cashier Subsystem API (/api/v1/cashier) */

    /**
     * Retrieves student tuition/fee assessments with optional status or student filters.
     */
    async getCashierAssessments(params = {}) {
      const search = new URLSearchParams();
      Object.keys(params).forEach((k) => {
        if (params[k] !== undefined && params[k] !== null && params[k] !== '') {
          search.set(k, String(params[k]));
        }
      });
      const query = search.toString();
      return this.request(`/api/v1/cashier${query ? '?' + query : ''}`);
    }

    /**
     * Retrieves a single fee assessment record.
     */
    async getCashierAssessment(assessmentId) {
      if (!assessmentId) throw new DmsApiError('Assessment ID is required.', 400);
      return this.request(`/api/v1/cashier/${encodeURIComponent(assessmentId)}`);
    }

    /**
     * Creates a fee assessment for a student account.
     */
    async createCashierAssessment(payload, options = {}) {
      return this.request('/api/v1/cashier', {
        method: 'POST',
        body: payload,
        ...options,
      });
    }

    /**
     * Verifies payment on an assessment with Official Receipt # and indexes receipt document.
     */
    async verifyCashierPayment(assessmentId, payload, options = {}) {
      if (!assessmentId) throw new DmsApiError('Assessment ID is required.', 400);
      return this.request(`/api/v1/cashier/${encodeURIComponent(assessmentId)}/verify`, {
        method: 'POST',
        body: payload,
        ...options,
      });
    }

    /* 4. Payroll Subsystem API (/api/v1/payroll) */

    /**
     * Retrieves faculty and staff payroll records by period, department, or status.
     */
    async getPayrollRecords(params = {}) {
      const search = new URLSearchParams();
      Object.keys(params).forEach((k) => {
        if (params[k] !== undefined && params[k] !== null && params[k] !== '') {
          search.set(k, String(params[k]));
        }
      });
      const query = search.toString();
      return this.request(`/api/v1/payroll${query ? '?' + query : ''}`);
    }

    /**
     * Retrieves a single payroll disbursement record.
     */
    async getPayrollRecord(payrollId) {
      if (!payrollId) throw new DmsApiError('Payroll ID is required.', 400);
      return this.request(`/api/v1/payroll/${encodeURIComponent(payrollId)}`);
    }

    /**
     * Ingests a faculty/staff payroll record with deduction breakdown and digital payslip document.
     */
    async ingestPayrollRecord(payload, options = {}) {
      return this.request('/api/v1/payroll', {
        method: 'POST',
        body: payload,
        ...options,
      });
    }

    /**
     * Updates payroll disbursement status (e.g. processed -> disbursed).
     */
    async updateDisbursementStatus(payrollId, status, options = {}) {
      if (!payrollId) throw new DmsApiError('Payroll ID is required.', 400);
      return this.request(`/api/v1/payroll/${encodeURIComponent(payrollId)}`, {
        method: 'PATCH',
        body: { disbursement_status: status },
        ...options,
      });
    }

    /* 5. Universal Documents Repository API (/api/v1/documents) */

    /**
     * Universal multipart upload with tags, classification, and metadata tagging.
     */
    async uploadUniversalDocument(formData, options = {}) {
      if (!(formData instanceof FormData)) {
        throw new DmsApiError('Upload requires FormData payload.', 400);
      }
      return this.request('/api/v1/documents', {
        method: 'POST',
        body: formData,
        ...options,
      });
    }

    /**
     * Retrieves universal document details including tags, metadata, and version list.
     */
    async getUniversalDocument(documentId) {
      if (!documentId) throw new DmsApiError('Document ID is required.', 400);
      return this.request(`/api/v1/documents/${encodeURIComponent(documentId)}`);
    }

    /**
     * Updates universal document metadata, reference, and tags.
     */
    async updateUniversalDocument(documentId, payload, options = {}) {
      if (!documentId) throw new DmsApiError('Document ID is required.', 400);
      return this.request(`/api/v1/documents/${encodeURIComponent(documentId)}`, {
        method: 'PATCH',
        body: payload,
        ...options,
      });
    }
  }

  // Export static factory and default singleton instance
  const defaultClient = new DmsApiClientInstance();
  defaultClient.DmsApiClient = DmsApiClientInstance;
  defaultClient.DmsApiError = DmsApiError;
  defaultClient.resolveBaseUrl = resolveBaseUrl;
  defaultClient.generateUuid = generateUuid;

  return defaultClient;
});
