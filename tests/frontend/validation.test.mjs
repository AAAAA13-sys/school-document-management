import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const context = {
  window: {},
  document: { body: { dataset: { preview: 'false' } } },
  jQuery: () => {},
};
vm.runInNewContext(fs.readFileSync('public/js/dms.js', 'utf8'), context);
const { fileError, strongPassword } = context.window.DmsValidation;
test('file validation accepts any extension and rejects oversized or missing files', () => {
  assert.equal(fileError({ name: 'document.PDF', size: 10 * 1024 * 1024 }), '');
  assert.match(fileError({ name: 'document.pdf', size: 10 * 1024 * 1024 + 1 }), /10 MB/);
  for (const name of ['archive.zip', 'audio.mp3', 'drawing.svg', 'source.php', 'README'])
    assert.equal(fileError({ name, size: 12 }), '');
  assert.match(fileError(null), /Choose a file/);
});
test('password hint validation covers all required character classes', () => {
  assert.equal(strongPassword('FrontendTest!2026'), true);
  for (const password of [
    'short!2A',
    'alllowercase!2026',
    'ALLUPPERCASE!2026',
    'NoSymbols202612',
    'NoNumbersHere!',
  ])
    assert.equal(strongPassword(password), false, password);
});
test('frontend has no manual scan controls and does not fake successful scans', () => {
  const views = [
    'resources/views/partials/upload.blade.php',
    'resources/views/document.blade.php',
    'resources/views/admin.blade.php',
  ]
    .map((p) => fs.readFileSync(p, 'utf8'))
    .join('\n');
  assert.match(views, /automatically in the background/);
  assert.doesNotMatch(views, />\s*Scan (file|now)\s*</i);
  assert.doesNotMatch(
    fs.readFileSync('public/js/dms.js', 'utf8'),
    /scan_status\s*[:=]\s*['"]Clean/
  );
});
test('form submissions submit to backend and handle upload asynchronously', () => {
  const js = fs.readFileSync('public/js/dms.js', 'utf8');
  assert.match(js, /this\.id !== 'upload-form'/);
  assert.match(js, /new FormData\(this\)/);
});
