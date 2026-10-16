import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// Execute the actual polling block with a scan response arriving during UI editing.
const js = fs.readFileSync('public/js/dms.js', 'utf8');
const startIndex = js.search(/const scanUrl\s*=/);
const endIndex = js.search(/let state\s*=\s*\{\}/);
const polling = js.slice(startIndex, endIndex);
for (const editing of ['none', 'draft', 'modal'])
  test(`scan refresh preserves ${editing} state`, () => {
    let tick,
      input,
      reloads = 0,
      stopped = 0,
      message = '';
    const $ = (selector) => ({
      length: 1,
      attr: (name) => (name === 'data-scan-status-url' ? '/scan-status' : 'Pending'),
      on: (_events, handler) => {
        input = handler;
      },
    });
    $.getJSON = () => ({
      done: (handler) => {
        handler({ versions: [{ id: 'version-1', scan_status: 'Clean' }] });
        return { fail: () => {} };
      },
    });
    vm.runInNewContext(polling, {
      $,
      preview: false,
      document: {
        hidden: false,
        querySelector: () => (editing === 'modal' ? {} : null),
      },
      setInterval: (handler) => {
        tick = handler;
        return 1;
      },
      clearInterval: () => {
        stopped++;
      },
      location: {
        reload: () => {
          reloads++;
        },
      },
      toast: (value) => {
        message = value;
      },
    });
    if (editing === 'draft') input();
    tick();
    assert.equal(stopped, 1);
    assert.equal(reloads, editing === 'none' ? 1 : 0);
    if (editing !== 'none') assert.match(message, /saving your changes/);
  });
