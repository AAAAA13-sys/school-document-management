/**
 * TypeScript Type Definitions for PLP DMS Enterprise API Client
 * Authoritative Cross-Subsystem Contract (Cycle 2)
 */

export type SubsystemCode = 'OAS' | 'ES' | 'EMS' | 'PMS' | 'DMS';

export type SubsystemStatus = 'Online' | 'Degraded' | 'Offline';

export type Classification = 'Internal' | 'Confidential' | 'Restricted';

export type ScanStatus = 'Clean' | 'Pending' | 'Failed' | 'Quarantined';

export type SharePermission = 'Viewer' | 'Commenter' | 'Editor';

export type AdmissionStatus = 'pending' | 'under_review' | 'admitted' | 'rejected' | 'waitlisted';

export type EnrollmentStatus = 'enrolled' | 'pending' | 'dropped' | 'graduated';

export type PaymentStatus = 'assessed' | 'partially_paid' | 'verified' | 'cancelled';

export type DisbursementStatus = 'pending' | 'processed' | 'disbursed' | 'failed';

export interface SubsystemHealthInfo {
  code: SubsystemCode;
  name: string;
  department: string;
  status: SubsystemStatus;
  latencyMs: number;
  lastContact?: string;
  syncMode?: string;
  intakeRate?: string;
  documentCount?: number;
  queueCount?: number;
}

export interface UnifiedMetrics {
  totalDocuments: number;
  activeAccounts: number;
  connectedSubsystems: number;
  securityBlocked: number;
  crossSubsystemShares: number;
  subsystems: SubsystemHealthInfo[];
}

export interface DepartmentTransaction {
  id: number;
  source: SubsystemCode;
  department: string;
  recordType: string;
  recordId: string;
  description?: string;
  operation: 'upsert' | 'delete' | 'release' | 'verify';
  revision: number;
  applied: boolean;
  status?: string;
  createdAt: string;
  details?: Record<string, unknown>;
}

export interface ApplicantDocumentModel {
  id: number;
  applicant_id: number;
  document_type: string;
  document_id: string;
  verification_status: 'pending' | 'verified' | 'rejected';
  notes?: string;
  verified_at?: string;
  document?: DocumentModel;
}

export interface StudentApplicantModel {
  id: number;
  applicant_number: string;
  first_name: string;
  middle_name?: string | null;
  last_name: string;
  suffix?: string | null;
  email: string;
  phone: string;
  program_applied: string;
  admission_status: AdmissionStatus;
  school_id: string;
  campus: string;
  applied_at: string;
  requirements?: Array<{
    type: string;
    document_id: string;
    status: string;
    notes?: string;
  }>;
  applicant_documents?: ApplicantDocumentModel[];
  metadata?: Record<string, unknown>;
}

export interface CourseAllocation {
  course_code: string;
  description: string;
  units: number;
  section: string;
  schedule?: string;
}

export interface StudentEnrollmentModel {
  id: number;
  student_number: string;
  applicant_id?: number | null;
  program: string;
  year_level: number;
  academic_year: string;
  semester: string;
  enrolled_units: number;
  course_allocations: CourseAllocation[];
  enrollment_status: EnrollmentStatus;
  clearance_status: 'cleared' | 'pending' | 'hold';
  cor_document_id?: string | null;
  cor_document?: DocumentModel;
  created_at: string;
  updated_at: string;
}

export interface CashierAssessmentModel {
  id: number;
  assessment_number: string;
  student_number: string;
  student_name: string;
  account_type: 'tuition' | 'laboratory' | 'graduation' | 'miscellaneous';
  academic_year: string;
  semester: string;
  total_amount: number;
  amount_paid: number;
  balance: number;
  payment_method: 'cash' | 'online_transfer' | 'cheque';
  payment_status: PaymentStatus;
  or_number?: string | null;
  receipt_document_id?: string | null;
  verified_by?: string | null;
  verified_at?: string | null;
  created_at: string;
  updated_at: string;
}

export interface DeductionBreakdown {
  withholding_tax?: number;
  gsis?: number;
  philhealth?: number;
  pagibig?: number;
  [key: string]: number | undefined;
}

export interface PayrollRecordModel {
  id: number;
  payroll_period: string;
  employee_id: string;
  employee_name: string;
  department: string;
  employment_type: 'faculty' | 'staff' | 'admin';
  gross_pay: number;
  deductions: number;
  net_pay: number;
  deduction_breakdown: DeductionBreakdown;
  disbursement_status: DisbursementStatus;
  payslip_document_id?: string | null;
  disbursed_at?: string | null;
  created_at: string;
  updated_at: string;
}

export interface DocumentVersionModel {
  id: string;
  document_id: string;
  number: number;
  filename: string;
  mime: string;
  size: number;
  checksum: string;
  scan_status: ScanStatus;
  created_at: string;
}

export interface DocumentModel {
  id: string;
  owner_id: number;
  folder_id: string | null;
  source: SubsystemCode;
  title: string;
  subject?: string;
  reference?: string;
  category?: string;
  classification: Classification;
  revision: number;
  trashed_at: string | null;
  tags?: string[];
  metadata?: Record<string, unknown>;
  created_at: string;
  updated_at: string;
  latest_version?: DocumentVersionModel;
  starred?: boolean;
}

export interface DmsApiError extends Error {
  status: number;
  code: string;
  errors: Record<string, string[]>;
  duplicates: Array<{
    document_id: string;
    title: string;
    version_number: number;
    uploaded_at: string;
  }>;
}

export interface DmsApiClientConfig {
  baseUrl?: string;
  timeout?: number;
  maxRetries?: number;
}

export interface DmsApiClientInstance {
  baseUrl: string;
  timeout: number;
  maxRetries: number;
  setBaseUrl(url: string): void;
  onLoadingChange(listener: (isLoading: boolean, activeRequests: number, requestPath?: string) => void): () => void;
  onError(listener: (error: DmsApiError) => void): () => void;
  request<T = unknown>(path: string, options?: RequestInit & { retryable?: boolean; skipIdempotency?: boolean; idempotencyKey?: string }): Promise<T>;
  
  // Dashboard & Integration Health
  getSubsystemStatuses(): Promise<{ subsystems: SubsystemHealthInfo[] }>;
  getUnifiedMetrics(): Promise<UnifiedMetrics>;
  getDepartmentTransactions(source?: string, options?: { limit?: number; after?: number }): Promise<DepartmentTransaction[]>;

  // Document Operations
  getDocuments(params?: Record<string, string | number | boolean>): Promise<{ data: DocumentModel[]; meta: { current_page: number; total: number } }>;
  getDocument(documentId: string): Promise<{ document: DocumentModel; versions: DocumentVersionModel[] }>;
  uploadDocument(formData: FormData, options?: RequestInit): Promise<{ document_id: string; version_id: string; revision: number; scan_status: ScanStatus }>;
  uploadVersion(documentId: string, formData: FormData, options?: RequestInit): Promise<{ document_id: string; version_id: string; revision: number; scan_status: ScanStatus }>;
  updateDocument(documentId: string, payload: Record<string, unknown>, options?: RequestInit): Promise<{ message: string; revision: number }>;
  trashDocument(documentId: string, revision: number, reason?: string): Promise<{ status: string; message: string }>;
  restoreDocument(documentId: string, revision: number): Promise<{ status: string; message: string }>;
  toggleStar(documentId: string): Promise<{ starred: boolean; document_id: string }>;
  pollScanStatus(documentId: string): Promise<{ versions: Array<{ id: string; scan_status: ScanStatus }> }>;
  getDownloadUrl(versionId: string): string;
  getPreviewUrl(versionId: string): string;

  // Inter-Service Admissions API
  getAdmissions(params?: Record<string, string | number | boolean>): Promise<{ status: string; data: StudentApplicantModel[]; meta: { current_page: number; total: number } }>;
  getAdmission(applicantId: string | number): Promise<{ status: string; data: StudentApplicantModel }>;
  createApplicant(payload: Partial<StudentApplicantModel>, options?: RequestInit): Promise<{ status: string; message: string; data: StudentApplicantModel }>;
  uploadApplicantDocument(applicantId: string | number, formData: FormData, options?: RequestInit): Promise<{ status: string; data: ApplicantDocumentModel }>;

  // Inter-Service Enrollment API
  getEnrollments(params?: Record<string, string | number | boolean>): Promise<{ status: string; data: StudentEnrollmentModel[]; meta: { current_page: number; total: number } }>;
  getEnrollment(enrollmentId: string | number): Promise<{ status: string; data: StudentEnrollmentModel }>;
  enrollStudent(payload: Partial<StudentEnrollmentModel>, options?: RequestInit): Promise<{ status: string; message: string; data: StudentEnrollmentModel }>;
  updateEnrollment(enrollmentId: string | number, payload: Partial<StudentEnrollmentModel>, options?: RequestInit): Promise<{ status: string; data: StudentEnrollmentModel }>;

  // Inter-Service Cashier API
  getCashierAssessments(params?: Record<string, string | number | boolean>): Promise<{ status: string; data: CashierAssessmentModel[]; meta: { current_page: number; total: number } }>;
  getCashierAssessment(assessmentId: string | number): Promise<{ status: string; data: CashierAssessmentModel }>;
  createCashierAssessment(payload: Partial<CashierAssessmentModel>, options?: RequestInit): Promise<{ status: string; message: string; data: CashierAssessmentModel }>;
  verifyCashierPayment(assessmentId: string | number, payload: { or_number: string; amount_paid: number; payment_method: string; receipt_document_id?: string; verified_by?: string }, options?: RequestInit): Promise<{ status: string; message: string; data: CashierAssessmentModel }>;

  // Inter-Service Payroll API
  getPayrollRecords(params?: Record<string, string | number | boolean>): Promise<{ status: string; data: PayrollRecordModel[]; meta: { current_page: number; total: number } }>;
  getPayrollRecord(payrollId: string | number): Promise<{ status: string; data: PayrollRecordModel }>;
  ingestPayrollRecord(payload: Partial<PayrollRecordModel>, options?: RequestInit): Promise<{ status: string; message: string; data: PayrollRecordModel }>;
  updateDisbursementStatus(payrollId: string | number, status: DisbursementStatus, options?: RequestInit): Promise<{ status: string; data: PayrollRecordModel }>;

  // Universal Document Repository API
  uploadUniversalDocument(formData: FormData, options?: RequestInit): Promise<{ document_id: string; version_id: string; revision: number; scan_status: ScanStatus }>;
  getUniversalDocument(documentId: string): Promise<{ data: DocumentModel }>;
  updateUniversalDocument(documentId: string, payload: { reference?: string; tags?: string[]; metadata?: Record<string, unknown> }, options?: RequestInit): Promise<{ status: string; data: DocumentModel }>;

  // Sharing & Packets
  createShare(documentId: string, recipientId: number, permission?: SharePermission, expiresAt?: string | null): Promise<{ status: string; message: string }>;
  revokeShare(documentId: string, shareId: number): Promise<{ status: string; message: string }>;
  addComment(documentId: string, body: string): Promise<{ message: string }>;
  getApplicantPackets(params?: Record<string, string | number>): Promise<{ data: unknown[] }>;
  getApplicantPacket(packetId: string): Promise<unknown>;
  releaseApplicantPacket(packetData: unknown): Promise<{ status: string; packet_id: string }>;
}

export declare const DmsApiClient: DmsApiClientInstance & {
  DmsApiClient: new (options?: DmsApiClientConfig) => DmsApiClientInstance;
  DmsApiError: new (message: string, status?: number, code?: string, errors?: Record<string, string[]>, duplicates?: unknown[]) => DmsApiError;
  resolveBaseUrl: (customUrl?: string) => string;
  generateUuid: () => string;
};

export default DmsApiClient;
