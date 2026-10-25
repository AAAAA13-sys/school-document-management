/**
 * TypeScript Type Definitions for PLP DMS Enterprise API Client
 */

export type SubsystemCode = 'OAS' | 'ES' | 'EMS' | 'PMS' | 'DMS';

export type SubsystemStatus = 'Online' | 'Degraded' | 'Offline';

export type Classification = 'Internal' | 'Confidential' | 'Restricted';

export type ScanStatus = 'Clean' | 'Pending' | 'Failed' | 'Quarantined';

export type SharePermission = 'Viewer' | 'Commenter' | 'Editor';

export interface SubsystemHealthInfo {
  code: SubsystemCode;
  name: string;
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
  recordType: string;
  recordId: string;
  operation: 'upsert' | 'delete' | 'release' | 'verify';
  revision: number;
  applied: boolean;
  createdAt: string;
  details?: Record<string, unknown>;
}

export interface ApplicantQueueItem {
  applicantId: string;
  name: string;
  program: string;
  documentCount: number;
  admitted: boolean;
  verified: boolean;
  released: boolean;
  updatedAt: string;
}

export interface IngestedDocumentItem {
  id: string;
  source: SubsystemCode;
  title: string;
  filename: string;
  classification: Classification;
  scanStatus: ScanStatus;
  versionNumber: number;
  sizeBytes: number;
  checksum: string;
  updatedAt: string;
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
  getSubsystemStatuses(): Promise<{ subsystems: SubsystemHealthInfo[] }>;
  getUnifiedMetrics(): Promise<UnifiedMetrics>;
  getDepartmentTransactions(source?: string, options?: { limit?: number; after?: number }): Promise<DepartmentTransaction[]>;
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
