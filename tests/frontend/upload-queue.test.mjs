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
const run = context.window.DmsUploadQueue;

test('upload queue sends at most two files concurrently and retries only failures with the same keys', async () => {
  const items = Array.from({ length: 6 }, (_, i) => ({
    key: 'stable-' + i,
    status: 'pending',
  }));
  let active = 0,
    max = 0;
  const calls = [];
  await run(
    items,
    async (item) => {
      calls.push(item.key);
      active++;
      max = Math.max(max, active);
      await new Promise((resolve) => setTimeout(resolve, 1));
      active--;
      if (item.key === 'stable-2') throw new Error('Network interrupted');
    },
    () => {}
  );
  assert.equal(max, 2);
  assert.equal(calls.length, 6);
  assert.equal(items[2].status, 'failed');
  assert.equal(items[2].error, 'Network interrupted');
  assert.equal(items.filter((i) => i.status === 'saved').length, 5);
  items[2].status = 'pending';
  const retry = [];
  await run(
    items,
    async (item) => retry.push(item.key),
    () => {}
  );
  assert.deepEqual(retry, ['stable-2']);
  assert.ok(items.every((i) => i.status === 'saved'));
});

test('files added during an active upload are drained without resending saved or invalid files', async () => {
  const items = [
    { key: 'first', status: 'pending' },
    { key: 'invalid', status: 'invalid' },
    { key: 'saved', status: 'saved' },
  ];
  const sent = [];
  await run(
    items,
    async (item) => {
      sent.push(item.key);
      if (item.key === 'first') items.push({ key: 'added', status: 'pending' });
    },
    () => {}
  );
  assert.deepEqual(sent, ['first', 'added']);
  assert.equal(items[1].status, 'invalid');
});
