import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync('public/js/pdf-viewer.mjs', 'utf8').replace(/^import[^\n]*\n/, '');
async function viewer(file) {
  const nodes = {};
  const node = (id) =>
    (nodes[id] ??= {
      hidden: true,
      disabled: true,
      style: {},
      textContent: '',
      addEventListener() {},
      setAttribute(name, value) {
        this[name] = value;
      },
    });
  let request;
  const context = {
    URLSearchParams,
    devicePixelRatio: 1,
    location: { search: '?file=' + encodeURIComponent(file) },
    document: { getElementById: node, documentElement: { clientWidth: 632 } },
    GlobalWorkerOptions: {},
    getDocument: (options) => {
      request = options;
      return {
        promise: Promise.resolve({
          numPages: 2,
          getPage: async () => ({
            getViewport: ({ scale }) => ({
              width: 600 * scale,
              height: 800 * scale,
            }),
            render: () => ({ promise: Promise.resolve() }),
          }),
        }),
      };
    },
  };
  await vm.runInNewContext('(async()=>{' + source + '})()', context);
  return { nodes, request };
}
test('PDF viewer loads only the protected preview path and renders the first page', async () => {
  const file = '/files/12345678-1234-4234-8234-123456789012/preview';
  const { nodes, request } = await viewer(file);
  assert.equal(request.url, file);
  assert.equal(request.isEvalSupported, false);
  assert.equal(request.enableXfa, false);
  assert.equal(nodes['page-count'].textContent, '1 / 2');
  assert.equal(nodes['pdf-canvas'].hidden, false);
  assert.equal(nodes.previous.disabled, true);
  assert.equal(nodes.next.disabled, false);
  assert.equal(nodes['pdf-canvas'].width, 600);
});
test('PDF viewer rejects external or arbitrary resource URLs without fetching them', async () => {
  for (const path of ['https://example.test/file.pdf', '/admin', '//example.test/file.pdf']) {
    const { nodes, request } = await viewer(path);
    assert.equal(request, undefined);
    assert.equal(nodes['pdf-error'].hidden, false);
    assert.equal(nodes['page-count'].textContent, 'Preview unavailable');
  }
});
