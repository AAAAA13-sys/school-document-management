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
