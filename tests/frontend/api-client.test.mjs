import { test } from 'node:test';
import assert from 'node:assert/strict';
import '../../public/js/dms-api-client.js';

const DmsApiClient = globalThis.DmsApiClient;

test('API Client initializes with default URL or env variables', () => {
  const originalEnv = { ...process.env };

  try {
    // 1. Fallback / Default
    delete process.env.NEXT_PUBLIC_API_URL;
    delete process.env.VITE_API_URL;
    delete process.env.DMS_API_URL;
    assert.equal(DmsApiClient.resolveBaseUrl(), '');

    // 2. NEXT_PUBLIC_API_URL priority
    process.env.NEXT_PUBLIC_API_URL = 'https://next-api.plp.edu.ph';
    assert.equal(DmsApiClient.resolveBaseUrl(), 'https://next-api.plp.edu.ph');

    // 3. VITE_API_URL fallback
    delete process.env.NEXT_PUBLIC_API_URL;
    process.env.VITE_API_URL = 'https://vite-api.plp.edu.ph';
    assert.equal(DmsApiClient.resolveBaseUrl(), 'https://vite-api.plp.edu.ph');

    // 4. Custom parameter override
    assert.equal(DmsApiClient.resolveBaseUrl('https://custom.plp.edu.ph/'), 'https://custom.plp.edu.ph');
  } finally {
    process.env = originalEnv;
  }
});

test('UUID generator produces valid RFC 4122 v4 UUIDs', () => {
  const uuid = DmsApiClient.generateUuid();
  assert.match(
    uuid,
    /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
  );
});

test('Download and Preview URL generators format correct paths', () => {
  const client = new DmsApiClient.DmsApiClient({ baseUrl: 'https://dms.plp.edu.ph' });
  const versionId = 'f5c317b4-1018-4e4f-b29b-0e0fd0398b02';

  assert.equal(
    client.getDownloadUrl(versionId),
    'https://dms.plp.edu.ph/files/f5c317b4-1018-4e4f-b29b-0e0fd0398b02'
  );
  assert.equal(
    client.getPreviewUrl(versionId),
    'https://dms.plp.edu.ph/files/f5c317b4-1018-4e4f-b29b-0e0fd0398b02/preview'
  );
  assert.equal(client.getDownloadUrl(''), '#');
});

test('DmsApiError correctly parses status, code, and errors', () => {
  const err = new DmsApiClient.DmsApiError(
    'Validation failed on document upload.',
    422,
    'validation_error',
    { title: ['Title is required.'], file: ['File size exceeds 10 MB.'] },
    [{ document_id: '1234', title: 'Existing' }]
  );

  assert.equal(err.name, 'DmsApiError');
  assert.equal(err.status, 422);
  assert.equal(err.code, 'validation_error');
  assert.equal(err.errors.title[0], 'Title is required.');
  assert.equal(err.duplicates.length, 1);
});

test('Loading and error listeners trigger properly on requests', async () => {
  const client = new DmsApiClient.DmsApiClient({ baseUrl: 'http://127.0.0.1:9999' });
  let loadingState = false;
  let requestCount = 0;

  const unsubscribe = client.onLoadingChange((isLoading, count) => {
    loadingState = isLoading;
    requestCount = count;
  });

  // Mock global fetch
  const originalFetch = globalThis.fetch;
  globalThis.fetch = async (url, options) => {
    assert.match(url, /\/documents/);
    assert.equal(options.headers.get('Accept'), 'application/json');
    return {
      ok: true,
      status: 200,
      headers: new Headers({ 'content-type': 'application/json' }),
      json: async () => ({ data: [], meta: { total: 0 } }),
    };
  };

  try {
    const res = await client.getDocuments({ source: 'EMS' });
    assert.deepEqual(res.data, []);
    assert.equal(loadingState, false);
    assert.equal(requestCount, 0);
  } finally {
    globalThis.fetch = originalFetch;
    unsubscribe();
  }
});

test('Admissions API methods construct correct requests', async () => {
  const client = new DmsApiClient.DmsApiClient({ baseUrl: 'https://dms.plp.edu.ph' });
  const originalFetch = globalThis.fetch;
  const calls = [];

  globalThis.fetch = async (url, options) => {
    calls.push({ url, options });
    return {
      ok: true,
      status: 200,
      headers: new Headers({ 'content-type': 'application/json' }),
      json: async () => ({ success: true }),
    };
  };

  try {
    // 1. getAdmissions with query parameters
    await client.getAdmissions({ status: 'Admitted', program_code: 'BSIT' });
    assert.equal(calls[0].url, 'https://dms.plp.edu.ph/api/v1/admissions?status=Admitted&program_code=BSIT');
    assert.equal(calls[0].options.method, 'GET');

    // 2. getAdmission
    await client.getAdmission('APP-2026-001');
    assert.equal(calls[1].url, 'https://dms.plp.edu.ph/api/v1/admissions/APP-2026-001');

    // 3. createApplicant
    const applicantPayload = { first_name: 'Maria', last_name: 'Santos', applied_program: 'BSCS' };
    await client.createApplicant(applicantPayload);
    assert.equal(calls[2].url, 'https://dms.plp.edu.ph/api/v1/admissions');
    assert.equal(calls[2].options.method, 'POST');
    assert.equal(JSON.parse(calls[2].options.body).first_name, 'Maria');
    assert.ok(calls[2].options.headers.has('Idempotency-Key'));

    // 4. uploadApplicantDocument
    const fakeForm = new FormData();
    fakeForm.append('document_type', 'Form 137');
    await client.uploadApplicantDocument('APP-2026-001', fakeForm);
    assert.equal(calls[3].url, 'https://dms.plp.edu.ph/api/v1/admissions/APP-2026-001/documents');
    assert.equal(calls[3].options.method, 'POST');
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('Enrollment API methods construct correct requests', async () => {
  const client = new DmsApiClient.DmsApiClient({ baseUrl: 'https://dms.plp.edu.ph' });
  const originalFetch = globalThis.fetch;
  const calls = [];

  globalThis.fetch = async (url, options) => {
    calls.push({ url, options });
    return {
      ok: true,
      status: 200,
      headers: new Headers({ 'content-type': 'application/json' }),
      json: async () => ({ success: true }),
    };
  };

  try {
    // 1. getEnrollments
    await client.getEnrollments({ enrollment_status: 'Enrolled' });
    assert.equal(calls[0].url, 'https://dms.plp.edu.ph/api/v1/enrollment?enrollment_status=Enrolled');

    // 2. getEnrollment
    await client.getEnrollment('ENR-2026-008');
    assert.equal(calls[1].url, 'https://dms.plp.edu.ph/api/v1/enrollment/ENR-2026-008');

    // 3. enrollStudent
    await client.enrollStudent({ applicant_id: 'APP-001', student_number: '2026-0001', program_code: 'BSIT' });
    assert.equal(calls[2].url, 'https://dms.plp.edu.ph/api/v1/enrollment');
    assert.equal(calls[2].options.method, 'POST');

    // 4. updateEnrollment
    await client.updateEnrollment('ENR-2026-008', { total_units: 24, status: 'Clear' });
    assert.equal(calls[3].url, 'https://dms.plp.edu.ph/api/v1/enrollment/ENR-2026-008');
    assert.equal(calls[3].options.method, 'PATCH');
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('Cashier API methods construct correct requests', async () => {
  const client = new DmsApiClient.DmsApiClient({ baseUrl: 'https://dms.plp.edu.ph' });
  const originalFetch = globalThis.fetch;
  const calls = [];

  globalThis.fetch = async (url, options) => {
    calls.push({ url, options });
    return {
      ok: true,
      status: 200,
      headers: new Headers({ 'content-type': 'application/json' }),
      json: async () => ({ success: true }),
    };
  };

  try {
    // 1. getCashierAssessments
    await client.getCashierAssessments({ payment_status: 'Unpaid' });
    assert.equal(calls[0].url, 'https://dms.plp.edu.ph/api/v1/cashier?payment_status=Unpaid');

    // 2. getCashierAssessment
    await client.getCashierAssessment('CAS-2026-009');
    assert.equal(calls[1].url, 'https://dms.plp.edu.ph/api/v1/cashier/CAS-2026-009');

    // 3. createCashierAssessment
    await client.createCashierAssessment({ student_number: '2026-0001', total_amount: 8500 });
    assert.equal(calls[2].url, 'https://dms.plp.edu.ph/api/v1/cashier');
    assert.equal(calls[2].options.method, 'POST');

    // 4. verifyCashierPayment
    await client.verifyCashierPayment('CAS-2026-009', {
      official_receipt_number: 'OR-991204',
      amount_paid: 8500,
      payment_method: 'Cash',
    });
    assert.equal(calls[3].url, 'https://dms.plp.edu.ph/api/v1/cashier/CAS-2026-009/verify');
    assert.equal(calls[3].options.method, 'POST');
    assert.equal(JSON.parse(calls[3].options.body).official_receipt_number, 'OR-991204');
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('Payroll API methods construct correct requests', async () => {
  const client = new DmsApiClient.DmsApiClient({ baseUrl: 'https://dms.plp.edu.ph' });
  const originalFetch = globalThis.fetch;
  const calls = [];

  globalThis.fetch = async (url, options) => {
    calls.push({ url, options });
    return {
      ok: true,
      status: 200,
      headers: new Headers({ 'content-type': 'application/json' }),
      json: async () => ({ success: true }),
    };
  };

  try {
    // 1. getPayrollRecords
    await client.getPayrollRecords({ period: '2026-10-B' });
    assert.equal(calls[0].url, 'https://dms.plp.edu.ph/api/v1/payroll?period=2026-10-B');

    // 2. getPayrollRecord
    await client.getPayrollRecord('PAY-2026-10-001');
    assert.equal(calls[1].url, 'https://dms.plp.edu.ph/api/v1/payroll/PAY-2026-10-001');

    // 3. ingestPayrollRecord
    await client.ingestPayrollRecord({ employee_number: 'EMP-042', gross_pay: 45000, net_pay: 38500 });
    assert.equal(calls[2].url, 'https://dms.plp.edu.ph/api/v1/payroll');
    assert.equal(calls[2].options.method, 'POST');

    // 4. updateDisbursementStatus
    await client.updateDisbursementStatus('PAY-2026-10-001', 'Disbursed');
    assert.equal(calls[3].url, 'https://dms.plp.edu.ph/api/v1/payroll/PAY-2026-10-001');
    assert.equal(calls[3].options.method, 'PATCH');
    assert.equal(JSON.parse(calls[3].options.body).disbursement_status, 'Disbursed');
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('Universal Documents API methods construct correct requests', async () => {
  const client = new DmsApiClient.DmsApiClient({ baseUrl: 'https://dms.plp.edu.ph' });
  const originalFetch = globalThis.fetch;
  const calls = [];

  globalThis.fetch = async (url, options) => {
    calls.push({ url, options });
    return {
      ok: true,
      status: 200,
      headers: new Headers({ 'content-type': 'application/json' }),
      json: async () => ({ success: true }),
    };
  };

  try {
    // 1. uploadUniversalDocument
    const fakeForm = new FormData();
    fakeForm.append('classification', 'CONFIDENTIAL');
    await client.uploadUniversalDocument(fakeForm);
    assert.equal(calls[0].url, 'https://dms.plp.edu.ph/api/v1/documents');
    assert.equal(calls[0].options.method, 'POST');

    // 2. getUniversalDocument
    await client.getUniversalDocument('DOC-9900');
    assert.equal(calls[1].url, 'https://dms.plp.edu.ph/api/v1/documents/DOC-9900');

    // 3. updateUniversalDocument
    await client.updateUniversalDocument('DOC-9900', { tags: ['accreditation', 'chede'] });
    assert.equal(calls[2].url, 'https://dms.plp.edu.ph/api/v1/documents/DOC-9900');
    assert.equal(calls[2].options.method, 'PATCH');
  } finally {
    globalThis.fetch = originalFetch;
  }
});

